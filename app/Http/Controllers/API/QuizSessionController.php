<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizSessions;
use App\Services\AdaptiveEloService;
use App\Services\EmbeddingService;
use App\Services\GeminiQuestionGeneratorService;
use App\Services\KnowledgeTracingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuizSessionController extends Controller
{
    public function __construct(
        private AdaptiveEloService $elo,
        private KnowledgeTracingService $knowledgeTracing,
        private EmbeddingService $embeddings,
        private GeminiQuestionGeneratorService $questionGenerator
    ) {
    }

    /**
     * Endpoint A: Initialize a fresh adaptive quiz session
     */
    public function start(Request $request)
    {
        $request->validate([
            'quiz_id' => 'required|exists:quizzes,id',
        ]);

        $user = Auth::user();

        // Close any lingering active sessions for this user/quiz combo
        QuizSessions::where('user_id', $user->id)
            ->where('quiz_id', $request->quiz_id)
            ->where('status', 'active')
            ->update(['status' => 'failed']);

        $quiz = Quiz::findOrFail($request->quiz_id);
        $questionsPool = $quiz->content_jsonb;
        $availableTopics = collect($questionsPool)->pluck('metadata.topic')->unique()->values()->all();

        // Two-stage adaptive selection: knowledge tracing picks the weakest topic to probe,
        // Elo picks the right difficulty within that topic.
        $targetTopic = $this->knowledgeTracing->pickWeightedTopic($user->id, $availableTopics);
        $firstQuestion = $this->elo->pickNextQuestion($quiz->id, $questionsPool, $user->elo_rating, [], $targetTopic);

        $session = QuizSessions::create([
            'user_id' => $user->id,
            'quiz_id' => $request->quiz_id,
            'current_streak' => 0,
            'lives_remaining' => 3,
            'difficulty_tier' => $this->tierForRating($user->elo_rating),
            'status' => 'active',
            'total_questions_answered' => 0,
            'total_correct_answers' => 0,
            'asked_question_ids' => $firstQuestion ? [$firstQuestion['id']] : [],
        ]);

        return response()->json([
            'message' => 'Adaptive quiz session initialized.',
            'session_id' => $session->id,
            'lives_remaining' => $session->lives_remaining,
            'difficulty_tier' => $session->difficulty_tier,
            'current_streak' => $session->current_streak,
            'student_rating' => round($user->elo_rating, 2),
            'topic_mastery' => $this->knowledgeTracing->getMasteryMap($user->id, $availableTopics),
            'question' => $this->sanitizeQuestion($firstQuestion)
        ], 201);
    }

    /**
     * Endpoint B: Evaluate current step and pull the next adaptive question
     */
    public function step(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:quiz_sessions,id',
            'question_id' => 'required|integer',
            'selected_option' => 'required|string',
        ]);

        $session = QuizSessions::where('id', $request->session_id)
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->firstOrFail();

        $quiz = Quiz::findOrFail($session->quiz_id);
        $questionsPool = $quiz->content_jsonb;
        $availableTopics = collect($questionsPool)->pluck('metadata.topic')->unique()->values()->all();

        // Find the specific question evaluated in this step
        $currentQuestion = collect($questionsPool)->firstWhere('id', $request->question_id);

        if (!$currentQuestion) {
            return response()->json(['error' => 'Question context not found.'], 404);
        }

        $isCorrect = ($currentQuestion['ground_truth'] === $request->selected_option);

        $user = Auth::user();
        $questionRating = $this->elo->getOrCreateQuestionRating($quiz->id, $currentQuestion);

        [$newStudentRating, $newQuestionRating] = $this->elo->updateRatings(
            $user->elo_rating,
            $questionRating->rating,
            $isCorrect
        );

        $user->elo_rating = $newStudentRating;
        $user->save();

        $questionRating->rating = $newQuestionRating;
        $questionRating->attempts += 1;
        $questionRating->save();

        $topic = data_get($currentQuestion, 'metadata.topic');
        if ($topic) {
            $this->knowledgeTracing->recordAttempt($user->id, $topic, $isCorrect);
        }

        // Track analytics totals
        $session->total_questions_answered += 1;
        $session->difficulty_tier = $this->tierForRating($newStudentRating);

        if ($isCorrect) {
            $session->total_correct_answers += 1;
            $session->current_streak += 1;
        } else {
            $session->lives_remaining -= 1;
            $session->current_streak = 0;

            // Game Over Hook: Check if the user ran out of heart lives
            if ($session->lives_remaining <= 0) {
                $session->status = 'failed';
                $session->save();

                return response()->json([
                    'is_correct' => false,
                    'correct_answer' => $currentQuestion['ground_truth'],
                    'explanation' => $currentQuestion['explanation'],
                    'lives_remaining' => 0,
                    'status' => 'failed',
                    'student_rating' => round($newStudentRating, 2),
                    'topic_mastery' => $this->knowledgeTracing->getMasteryMap($user->id, $availableTopics),
                    'related_practice' => $this->getRelatedPractice($quiz->id, $questionsPool, $currentQuestion),
                    'message' => 'Game Over! You ran out of hearts. ❤️❌'
                ]);
            }
        }

        // Two-stage adaptive selection: knowledge tracing picks the weakest topic to probe,
        // Elo picks the right difficulty within that topic.
        $askedIds = $session->asked_question_ids ?? [];
        $targetTopic = $this->knowledgeTracing->pickWeightedTopic($user->id, $availableTopics);
        $nextQuestion = $this->elo->pickNextQuestion($quiz->id, $questionsPool, $newStudentRating, $askedIds, $targetTopic);

        if ($nextQuestion) {
            $askedIds[] = $nextQuestion['id'];
        }
        $session->asked_question_ids = $askedIds;

        $session->save();

        return response()->json([
            'is_correct' => $isCorrect,
            'correct_answer' => $currentQuestion['ground_truth'],
            'hint' => $isCorrect ? null : $currentQuestion['hint'],
            'explanation' => $isCorrect ? null : $currentQuestion['explanation'],
            'related_practice' => $isCorrect ? [] : $this->getRelatedPractice($quiz->id, $questionsPool, $currentQuestion, $askedIds),
            'lives_remaining' => $session->lives_remaining,
            'difficulty_tier' => $session->difficulty_tier,
            'current_streak' => $session->current_streak,
            'student_rating' => round($newStudentRating, 2),
            'topic_mastery' => $this->knowledgeTracing->getMasteryMap($user->id, $availableTopics),
            'status' => 'active',
            'next_question' => $this->sanitizeQuestion($nextQuestion)
        ]);
    }

    /**
     * Endpoint C: Close active session and lock final scores
     */
    public function finalize(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:quiz_sessions,id'
        ]);

        $session = QuizSessions::where('id', $request->session_id)
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->firstOrFail();

        $session->status = 'completed';
        $session->save();

        // Calculate a safe floating-point final score percentage
        $scorePercentage = $session->total_questions_answered > 0
            ? round(($session->total_correct_answers / $session->total_questions_answered) * 100, 2)
            : 0;

        // Hooks can go here to update main curriculum maps or unlock badges if score > 80%

        $quiz = Quiz::findOrFail($session->quiz_id);
        $availableTopics = collect($quiz->content_jsonb)->pluck('metadata.topic')->unique()->values()->all();

        return response()->json([
            'message' => 'Quiz session finalized successfully.',
            'status' => 'completed',
            'total_answered' => $session->total_questions_answered,
            'total_correct' => $session->total_correct_answers,
            'final_score_percentage' => $scorePercentage,
            'student_rating' => round(Auth::user()->elo_rating, 2),
            'topic_mastery' => $this->knowledgeTracing->getMasteryMap(Auth::id(), $availableTopics),
            'unlocked_next' => ($scorePercentage >= 80.00)
        ]);
    }

    /**
     * Endpoint D: Generate a brand-new practice question (via Gemini) targeting
     * the student's weakest BKT topic, instead of only pulling from the static bank.
     */
    public function aiChallenge(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:quiz_sessions,id',
        ]);

        $session = QuizSessions::where('id', $request->session_id)
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->firstOrFail();

        $user = Auth::user();
        $quiz = Quiz::findOrFail($session->quiz_id);
        $availableTopics = collect($quiz->content_jsonb)->pluck('metadata.topic')->unique()->values()->all();

        $masteryMap = $this->knowledgeTracing->getMasteryMap($user->id, $availableTopics);
        $weakestTopic = collect($masteryMap)->sort()->keys()->first();
        $difficulty = $this->tierForRating($user->elo_rating);

        try {
            $generated = $this->questionGenerator->generate($weakestTopic, $difficulty);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'The AI question generator is temporarily unreachable.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 502);
        }

        $session->pending_ai_question = array_merge($generated, [
            'topic' => $weakestTopic,
            'difficulty' => $difficulty,
        ]);
        $session->save();

        return response()->json([
            'source' => 'ai_generated',
            'topic' => $weakestTopic,
            'difficulty' => $difficulty,
            'topic_mastery' => $masteryMap,
            'question' => [
                'question' => $generated['question'],
                'options' => $generated['options'],
            ],
        ], 201);
    }

    /**
     * Endpoint E: Evaluate the answer to the session's pending AI-generated question.
     */
    public function aiChallengeAnswer(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:quiz_sessions,id',
            'selected_option' => 'required|string',
        ]);

        $session = QuizSessions::where('id', $request->session_id)
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->firstOrFail();

        $pending = $session->pending_ai_question;
        if (!$pending) {
            return response()->json(['error' => 'No active AI challenge for this session. Call ai-challenge first.'], 404);
        }

        $user = Auth::user();
        $isCorrect = trim($pending['ground_truth']) === trim($request->selected_option);

        $this->knowledgeTracing->recordAttempt($user->id, $pending['topic'], $isCorrect);

        $session->pending_ai_question = null;
        $session->save();

        $quiz = Quiz::findOrFail($session->quiz_id);
        $availableTopics = collect($quiz->content_jsonb)->pluck('metadata.topic')->unique()->values()->all();

        return response()->json([
            'is_correct' => $isCorrect,
            'correct_answer' => $pending['ground_truth'],
            'hint' => $isCorrect ? null : $pending['hint'],
            'explanation' => $pending['explanation'],
            'topic' => $pending['topic'],
            'topic_mastery' => $this->knowledgeTracing->getMasteryMap($user->id, $availableTopics),
        ]);
    }

    /**
     * Helper to keep students from inspecting network requests to see the correct answer
     */
    private function sanitizeQuestion(?array $question): ?array
    {
        if (!$question) return null;

        return [
            'id' => $question['id'],
            'question' => $question['question'],
            'options' => $question['options'],
            'metadata' => $question['metadata']
        ];
    }

    /**
     * Maps a continuous Elo rating onto the 1-3 tier band used for display/gamification.
     */
    private function tierForRating(float $rating): int
    {
        if ($rating < 950) return 1;
        if ($rating > 1050) return 3;
        return 2;
    }

    /**
     * Semantically similar questions to review after a miss, via Hugging Face
     * embeddings + pgvector nearest-neighbor search. Never breaks the quiz
     * flow if the embedding backend is unavailable.
     */
    private function getRelatedPractice(int $quizId, array $questionsPool, array $missedQuestion, array $excludeIds = []): array
    {
        try {
            $this->embeddings->ensureEmbeddingsExist($quizId, $questionsPool);
            $similarIds = $this->embeddings->findSimilarQuestionIds($quizId, $missedQuestion['id'], 2, $excludeIds);
        } catch (\Throwable) {
            return [];
        }

        $byId = collect($questionsPool)->keyBy('id');

        return collect($similarIds)
            ->map(fn ($id) => $byId->get($id))
            ->filter()
            ->map(fn ($q) => [
                'id' => $q['id'],
                'question' => $q['question'],
                'explanation' => $q['explanation'],
                'topic' => data_get($q, 'metadata.topic'),
            ])
            ->values()
            ->all();
    }
}