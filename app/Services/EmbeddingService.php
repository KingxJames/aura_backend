<?php

namespace App\Services;

use App\Models\QuestionEmbedding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Semantic similarity for quiz questions, backed by the Hugging Face Inference
 * API (sentence-transformers/all-MiniLM-L6-v2, 384-dim) and pgvector cosine
 * distance for nearest-neighbor lookup.
 */
class EmbeddingService
{
    private const BATCH_SIZE = 20;

    /**
     * Embed a batch of strings in one HF Inference API call.
     * Returns one 384-float vector per input, in the same order.
     */
    public function embedBatch(array $texts): array
    {
        $model = config('services.huggingface.embedding_model');

        $response = Http::withToken(config('services.huggingface.token'))
            ->timeout(60)
            ->post("https://router.huggingface.co/hf-inference/models/{$model}/pipeline/feature-extraction", [
                'inputs' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Hugging Face embedding request failed ({$response->status()}): {$response->body()}"
            );
        }

        return $response->json();
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    private function toVectorLiteral(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    public function upsertEmbedding(int $quizId, int $questionId, array $vector): void
    {
        DB::statement(
            'INSERT INTO question_embeddings (quiz_id, question_id, embedding, created_at, updated_at)
             VALUES (?, ?, ?, now(), now())
             ON CONFLICT (quiz_id, question_id)
             DO UPDATE SET embedding = EXCLUDED.embedding, updated_at = now()',
            [$quizId, $questionId, $this->toVectorLiteral($vector)]
        );
    }

    /**
     * Embed and store any question in $questions that doesn't have a row yet.
     * Returns how many were newly embedded.
     */
    public function ensureEmbeddingsExist(int $quizId, array $questions): int
    {
        $allIds = array_column($questions, 'id');

        $existingIds = QuestionEmbedding::where('quiz_id', $quizId)
            ->whereIn('question_id', $allIds)
            ->pluck('question_id')
            ->all();

        $missingIds = array_diff($allIds, $existingIds);
        if (empty($missingIds)) {
            return 0;
        }

        $byId = collect($questions)->keyBy('id');
        $missingQuestions = collect($missingIds)->map(fn ($id) => $byId->get($id))->values();

        foreach ($missingQuestions->chunk(self::BATCH_SIZE) as $chunk) {
            $chunk = $chunk->values(); // chunk() keeps original keys; reindex so json_encode emits a JSON array, not an object
            $texts = $chunk->map(fn ($q) => $q['question'] . ' ' . ($q['explanation'] ?? ''))->all();
            $vectors = $this->embedBatch($texts);

            foreach ($chunk as $i => $question) {
                $this->upsertEmbedding($quizId, $question['id'], $vectors[$i]);
            }
        }

        return $missingQuestions->count();
    }

    /**
     * The $limit question ids (within the same quiz) whose embeddings are
     * closest, by cosine distance, to the given question's embedding.
     */
    public function findSimilarQuestionIds(int $quizId, int $questionId, int $limit = 2, array $excludeIds = []): array
    {
        $excludeIds = array_values(array_unique(array_merge($excludeIds, [$questionId])));
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));

        $rows = DB::select(
            "SELECT question_id, (embedding <=> (
                SELECT embedding FROM question_embeddings WHERE quiz_id = ? AND question_id = ?
             )) AS distance
             FROM question_embeddings
             WHERE quiz_id = ?
               AND question_id NOT IN ({$placeholders})
               AND embedding IS NOT NULL
             ORDER BY distance ASC
             LIMIT ?",
            [$quizId, $questionId, $quizId, ...$excludeIds, $limit]
        );

        return array_map(fn ($row) => $row->question_id, $rows);
    }
}
