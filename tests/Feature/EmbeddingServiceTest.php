<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Quiz;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuiz(): Quiz
    {
        $grade = Grade::create([
            'title' => 'Test Grade',
            'level_number' => 1,
            'description' => 'Test',
            'syllabus_focus' => 'Test',
        ]);

        return Quiz::create([
            'grade_id' => $grade->id,
            'title' => 'Test Quiz',
            'description' => 'Test',
            'content_jsonb' => [
                ['id' => 1, 'question' => 'Q1', 'options' => ['A', 'B'], 'ground_truth' => 'A', 'hint' => 'h', 'explanation' => 'e1', 'metadata' => ['topic' => 'pitch']],
                ['id' => 2, 'question' => 'Q2', 'options' => ['A', 'B'], 'ground_truth' => 'A', 'hint' => 'h', 'explanation' => 'e2', 'metadata' => ['topic' => 'pitch']],
            ],
        ]);
    }

    /** 384-dim vector with two controllable components, rest zero. */
    private function vector(float $a, float $b): array
    {
        $v = array_fill(0, 384, 0.0);
        $v[0] = $a;
        $v[1] = $b;
        return $v;
    }

    public function test_embed_batch_sends_the_inputs_and_parses_the_response(): void
    {
        Http::fake([
            'router.huggingface.co/*' => Http::response([[0.1, 0.2], [0.3, 0.4]]),
        ]);

        $embeddings = new EmbeddingService();
        $result = $embeddings->embedBatch(['hello', 'world']);

        $this->assertEquals([[0.1, 0.2], [0.3, 0.4]], $result);

        Http::assertSent(function ($request) {
            return $request['inputs'] === ['hello', 'world'];
        });
    }

    public function test_embed_batch_throws_on_http_failure(): void
    {
        Http::fake([
            'router.huggingface.co/*' => Http::response('server error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        (new EmbeddingService())->embedBatch(['hello']);
    }

    public function test_ensure_embeddings_exist_only_embeds_missing_questions(): void
    {
        $quiz = $this->makeQuiz();

        Http::fake(function ($request) {
            $count = count($request->data()['inputs']);
            return Http::response(array_fill(0, $count, array_fill(0, 384, 0.01)));
        });

        $embeddings = new EmbeddingService();

        $firstRun = $embeddings->ensureEmbeddingsExist($quiz->id, $quiz->content_jsonb);
        $this->assertEquals(2, $firstRun);
        Http::assertSentCount(1);

        $secondRun = $embeddings->ensureEmbeddingsExist($quiz->id, $quiz->content_jsonb);
        $this->assertEquals(0, $secondRun);
        Http::assertSentCount(1); // no new HTTP call since nothing was missing
    }

    public function test_ensure_embeddings_exist_handles_more_than_one_batch(): void
    {
        // Regression test: Collection::chunk() preserves original (non-reindexed) keys,
        // so anything past the first chunk used to serialize as a JSON object instead
        // of an array, which the Hugging Face pipeline rejected outright.
        $questions = [];
        for ($i = 1; $i <= 45; $i++) { // 3 batches at BATCH_SIZE=20
            $questions[] = [
                'id' => $i,
                'question' => "Question {$i}",
                'options' => ['A', 'B'],
                'ground_truth' => 'A',
                'hint' => 'h',
                'explanation' => "explanation {$i}",
                'metadata' => ['topic' => 'pitch'],
            ];
        }

        $quiz = $this->makeQuiz();
        $quiz->content_jsonb = $questions;
        $quiz->save();

        $receivedBatchSizes = [];
        Http::fake(function ($request) use (&$receivedBatchSizes) {
            $inputs = $request->data()['inputs'];
            $this->assertIsList($inputs, 'inputs must serialize as a JSON array, not an object');
            $receivedBatchSizes[] = count($inputs);
            return Http::response(array_fill(0, count($inputs), array_fill(0, 384, 0.01)));
        });

        $embeddings = new EmbeddingService();
        $count = $embeddings->ensureEmbeddingsExist($quiz->id, $questions);

        $this->assertEquals(45, $count);
        $this->assertEquals([20, 20, 5], $receivedBatchSizes);
        $this->assertEquals(45, DB::table('question_embeddings')->where('quiz_id', $quiz->id)->count());
    }

    public function test_find_similar_question_ids_orders_by_cosine_distance(): void
    {
        $quiz = $this->makeQuiz();

        $rows = [
            ['id' => 1, 'vector' => $this->vector(1.0, 0.0)],   // the "missed" question
            ['id' => 2, 'vector' => $this->vector(0.95, 0.05)], // closest
            ['id' => 3, 'vector' => $this->vector(0.5, 0.5)],   // middle
            ['id' => 4, 'vector' => $this->vector(0.05, 0.95)], // farthest
        ];

        foreach ($rows as $row) {
            DB::statement(
                'INSERT INTO question_embeddings (quiz_id, question_id, embedding, created_at, updated_at)
                 VALUES (?, ?, ?, now(), now())',
                [$quiz->id, $row['id'], '[' . implode(',', $row['vector']) . ']']
            );
        }

        $embeddings = new EmbeddingService();
        $ordered = $embeddings->findSimilarQuestionIds($quiz->id, 1, 3);

        $this->assertEquals([2, 3, 4], $ordered);
    }
}
