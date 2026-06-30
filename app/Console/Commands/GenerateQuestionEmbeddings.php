<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;

class GenerateQuestionEmbeddings extends Command
{
    protected $signature = 'questions:embed {quiz_id? : Only embed this quiz; defaults to all quizzes}';

    protected $description = 'Pre-compute sentence embeddings (via Hugging Face) for quiz questions missing one.';

    public function handle(EmbeddingService $embeddings): int
    {
        $quizzes = $this->argument('quiz_id')
            ? Quiz::where('id', $this->argument('quiz_id'))->get()
            : Quiz::all();

        if ($quizzes->isEmpty()) {
            $this->error('No matching quiz found.');
            return self::FAILURE;
        }

        foreach ($quizzes as $quiz) {
            $questions = $quiz->content_jsonb ?? [];
            $this->info("Quiz #{$quiz->id} \"{$quiz->title}\": " . count($questions) . ' questions in pool.');

            $newlyEmbedded = $embeddings->ensureEmbeddingsExist($quiz->id, $questions);

            $this->info("  -> embedded {$newlyEmbedded} new question(s).");
        }

        return self::SUCCESS;
    }
}
