<?php

namespace App\Services;

use App\Models\QuestionRating;
use Illuminate\Support\Collection;

class AdaptiveEloService
{
    private const STUDENT_K_FACTOR = 32;
    private const QUESTION_K_FACTOR = 16;

    private const DIFFICULTY_SEED_RATINGS = [
        1 => 900,
        2 => 1000,
        3 => 1100,
    ];

    /**
     * Probability that a contestant rated $ratingA beats one rated $ratingB
     * (standard Elo logistic curve).
     */
    public function expectedScore(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    /**
     * Apply one Elo update for a student-vs-question outcome.
     * Returns [newStudentRating, newQuestionRating].
     */
    public function updateRatings(float $studentRating, float $questionRating, bool $isCorrect): array
    {
        $studentExpected = $this->expectedScore($studentRating, $questionRating);
        $studentActual = $isCorrect ? 1.0 : 0.0;

        $newStudentRating = $studentRating + self::STUDENT_K_FACTOR * ($studentActual - $studentExpected);
        $newQuestionRating = $questionRating + self::QUESTION_K_FACTOR * ((1 - $studentActual) - (1 - $studentExpected));

        return [$newStudentRating, $newQuestionRating];
    }

    /**
     * Fetch (or create with a seeded rating) the QuestionRating rows for every
     * question id in $questions, keyed by question_id.
     */
    public function ensureRatingsExist(int $quizId, array $questions): Collection
    {
        $ids = array_column($questions, 'id');

        $existing = QuestionRating::where('quiz_id', $quizId)
            ->whereIn('question_id', $ids)
            ->get()
            ->keyBy('question_id');

        $missingIds = array_diff($ids, $existing->keys()->all());

        if (!empty($missingIds)) {
            $byId = collect($questions)->keyBy('id');

            foreach ($missingIds as $missingId) {
                $difficulty = data_get($byId->get($missingId), 'metadata.difficulty');
                $seed = self::DIFFICULTY_SEED_RATINGS[$difficulty] ?? 1000;

                $existing->put($missingId, QuestionRating::create([
                    'quiz_id' => $quizId,
                    'question_id' => $missingId,
                    'rating' => $seed,
                ]));
            }
        }

        return $existing;
    }

    public function getOrCreateQuestionRating(int $quizId, array $question): QuestionRating
    {
        return $this->ensureRatingsExist($quizId, [$question])->get($question['id']);
    }

    /**
     * Pick the question whose current rating is closest to the student's
     * rating, excluding any question ids already asked this session.
     * If $topicFilter is given, candidates are restricted to that topic
     * first (falling back to the full pool if that topic has nothing left).
     */
    public function pickNextQuestion(int $quizId, array $questionsPool, float $studentRating, array $excludeIds = [], ?string $topicFilter = null): ?array
    {
        $unseen = array_values(array_filter(
            $questionsPool,
            fn ($q) => !in_array($q['id'], $excludeIds)
        ));

        if (empty($unseen)) {
            // Session has exhausted the pool — start a fresh lap rather than dead-ending.
            $unseen = $questionsPool;
        }

        $candidates = $unseen;
        if ($topicFilter !== null) {
            $byTopic = array_values(array_filter(
                $unseen,
                fn ($q) => data_get($q, 'metadata.topic') === $topicFilter
            ));
            if (!empty($byTopic)) {
                $candidates = $byTopic;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        $ratings = $this->ensureRatingsExist($quizId, $candidates);

        $closest = collect($candidates)
            ->shuffle()
            ->sortBy(fn ($q) => abs($ratings->get($q['id'])->rating - $studentRating))
            ->first();

        return $closest;
    }
}
