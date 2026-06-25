<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuizSessionController extends Controller
{
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

        // Create a fresh live track profile (Difficulty Tier 2 = Medium)
        $session = QuizSessions::create([
            'user_id' => $user->id,
            'quiz_id' => $request->quiz_id,
            'current_streak' => 0,
            'lives_remaining' => 3,
            'difficulty_tier' => 2, 
            'status' => 'active',
            'total_questions_answered' => 0,
            'total_correct_answers' => 0,
        ]);

        // Extract the JSON pool from the Quiz template
        $quiz = Quiz::findOrFail($request->quiz_id);
        $questionsPool = $quiz->content_jsonb;

        // Backend Adaptive Selection: Find the first Medium question
        $firstQuestion = collect($questionsPool)->first(function ($q) {
            return data_get($q, 'metadata.difficulty') == 2;
        });

        // Strip ground truth before sending to frontend to prevent console cheating
        $cleanedQuestion = $this->sanitizeQuestion($firstQuestion);

        return response()->json([
            'message' => 'Adaptive quiz session initialized.',
            'session_id' => $session->id,
            'lives_remaining' => $session->lives_remaining,
            'difficulty_tier' => $session->difficulty_tier,
            'current_streak' => $session->current_streak,
            'question' => $cleanedQuestion
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

        // Find the specific question evaluated in this step
        $currentQuestion = collect($questionsPool)->firstWhere('id', $request->question_id);

        if (!$currentQuestion) {
            return response()->json(['error' => 'Question context not found.'], 404);
        }

        $isCorrect = ($currentQuestion['ground_truth'] === $request->selected_option);
        
        // Track analytics totals
        $session->total_questions_answered += 1;

        if ($isCorrect) {
            $session->total_correct_answers += 1;
            $session->current_streak += 1;

            // ADAPTIVE LOGIC: Hit a 3-correct streak? Scale Up Difficulty!
            if ($session->current_streak >= 3 && $session->difficulty_tier < 3) {
                $session->difficulty_tier += 1;
                $session->current_streak = 0; // Reset streak calculator for the next tier challenge
            }
        } else {
            $session->lives_remaining -= 1;
            
            // ADAPTIVE LOGIC: Missed a question? Break streak and drop difficulty tier back down
            $session->current_streak = 0;
            if ($session->difficulty_tier > 1) {
                $session->difficulty_tier -= 1;
            }

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
                    'message' => 'Game Over! You ran out of hearts. ❤️❌'
                ]);
            }
        }

        $session->save();

        // Pick the next best question matching the updated difficulty tier
        $nextQuestion = collect($questionsPool)
            ->where('metadata.difficulty', $session->difficulty_tier)
            ->shuffle() // Add variety
            ->first();

        // Fallback safety if specific pool runs empty
        if (!$nextQuestion) {
            $nextQuestion = collect($questionsPool)->random();
        }

        return response()->json([
            'is_correct' => $isCorrect,
            'correct_answer' => $currentQuestion['ground_truth'],
            'hint' => $isCorrect ? null : $currentQuestion['hint'],
            'explanation' => $isCorrect ? null : $currentQuestion['explanation'],
            'lives_remaining' => $session->lives_remaining,
            'difficulty_tier' => $session->difficulty_tier,
            'current_streak' => $session->current_streak,
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

        return response()->json([
            'message' => 'Quiz session finalized successfully.',
            'status' => 'completed',
            'total_answered' => $session->total_questions_answered,
            'total_correct' => $session->total_correct_answers,
            'final_score_percentage' => $scorePercentage,
            'unlocked_next' => ($scorePercentage >= 80.00)
        ]);
    }

    /**
     * Helper to keep students from inspecting network requests to see the correct answer
     */
    private function sanitizeQuestion($question)
    {
        if (!$question) return null;
        
        return [
            'id' => $question['id'],
            'question' => $question['question'],
            'options' => $question['options'],
            'metadata' => $question['metadata']
        ];
    }
}