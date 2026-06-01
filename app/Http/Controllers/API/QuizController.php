<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grade;          // Linked here: Manages the 'grades' table (Curriculum steps)
use App\Models\Quiz;           // Linked here: Manages the 'quizzes' table (JSON question blocks)
use App\Models\UserProgress;   // Linked here: Manages the 'user_progress' table (Student scores)
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class QuizController extends Controller
{
    /**
     * =========================================================================
     * ARCHITECTURE EXPLANATION: CONSOLIDATED SYLLABUS LIFE-CYCLE
     * =========================================================================
     * This controller consolidates the Grades, Quizzes, and UserProgress tables.
     * Instead of separating them into three individual controllers, grouping 
     * them here makes logical sense because a music Grade step contains Quizzes, 
     * and UserProgress records the scores earned on those specific Quizzes.
     * * 1. READ ALL (Index) - Fetch all grades with their nested quiz profiles.
     * Target Frontend: Grades & Quizzes Tab (Displays curriculum hierarchy)
     * GET /api/v1/curriculum
     */
    public function index(): JsonResponse
    {
        // ELOQUENT RELATIONSHIP MAPPING:
        // We call the 'Grade' model and use 'with("quizzes")' to eager-load 
        // every quiz associated with that grade level in a single query.
        $curriculum = Grade::with('quizzes')->orderBy('level_number', 'asc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Complete curriculum syllabus map retrieved.',
            'data' => $curriculum
        ]);
    }

    /**
     * 2. READ ONE (Show Quiz) - Fetch a single specific quiz module.
     * Target Frontend: Exam Screen (Loads the live test engine)
     * GET /api/v1/curriculum/quiz/{id}
     */
    public function showQuiz(string $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz module not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $quiz,
        ]);
    }

    /**
     * PROCESS ADAPTIVE TEST QUESTION SUBMISSION
     * POST /api/v1/curriculum/quiz/submit
     */
    public function submitQuiz(Request $request): JsonResponse
    {
        $request->validate([
            'quiz_id' => 'required|uuid|exists:quizzes,id',
            'question_id' => 'required|string',
            'user_answer' => 'required|string',
        ]);

        $user_answer = $request->input('user_answer');

        $user = $request->user();
        $quiz = Quiz::findOrFail($request->quiz_id);

        // Find the specific question item within our JSONB curriculum payload
        $questions = collect($quiz->content_jsonb);
        $question = $questions->firstWhere('question_id', $request->question_id);

        if (!$question) {
            return response()->json(['message' => 'Target question tracking identifier not found.'], 404);
        }

        // 1. Verify if the user's answer matches the correct answer string key
        $isCorrect = (trim($user_answer) === trim($question['correct_answer']));

        // 2. Fetch or create the active tracking session record for this user and quiz
        $progress = UserProgress::firstOrCreate(
            [
                'user_id' => $user->id,
                'quiz_id' => $quiz->id
            ],
            [
                'score' => 0,
                'current_streak' => 0,
                'current_tier' => 'standard'
            ]
        );

        // 3. Process the Adaptive State Engine
        if ($isCorrect) {
            $progress->current_streak += 1;

            // THRESHOLD CHECK: If user hits 3 correct answers in a row, unlock advanced MTB questions
            if ($progress->current_streak >= 3) {
                $progress->current_tier = 'advanced';
            }
        } else {
            // Failure Penalty: Instantly reset active streak metrics and demote back to standard pool
            $progress->current_streak = 0;
            $progress->current_tier = 'standard';
        }

        // 4. Calculate overall performance summary metric score percentage for this submission
        // (For simplicity in this step, updating records to demonstrate current operational metrics)
        $progress->score = $isCorrect ? min($progress->score + 10, 100) : max($progress->score - 5, 0);
        $progress->save();

        // 5. Select the next question candidate belonging exclusively to the student's current difficulty tier
        $nextQuestionPool = $questions->where('difficulty', $progress->current_tier);

        // Exclude the current question so they don't get it immediately back-to-back
        $tierExcludingCurrent = $nextQuestionPool->where('question_id', '!=', $request->question_id);
        $allExcludingCurrent = $questions->where('question_id', '!=', $request->question_id);

        if ($tierExcludingCurrent->isNotEmpty()) {
            $nextQuestion = $tierExcludingCurrent->random();
        } elseif ($allExcludingCurrent->isNotEmpty()) {
            $nextQuestion = $allExcludingCurrent->random();
        } else {
            // Quiz contains only one question; return the same question safely.
            $nextQuestion = $question;
        }

        return response()->json([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_answer' => $question['correct_answer'],
            'current_streak' => $progress->current_streak,
            'allocated_tier' => $progress->current_tier,
            'message' => $progress->current_tier === 'advanced'
                ? 'Excellent pace! Advanced MusicTheoryBench criteria unlocked.'
                : 'Response recorded.',
            'next_question' => [
                'question_id' => $nextQuestion['question_id'],
                'type' => $nextQuestion['type'],
                'prompt' => $nextQuestion['prompt'],
                'options' => $nextQuestion['options']
                // Notice we drop 'correct_answer' so the frontend client cannot see it in network requests!
            ]
        ], 200);
    }

    /**
     * 4. READ PROGRESS (Student Analytics) - Fetch a historical report of student scores.
     * Target Frontend: Progress Analytics Tab (Renders charts/grade book graphs)
     * GET /api/v1/curriculum/progress?user_id={uuid}
     */
    public function studentProgress(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        // ELOQUENT REVERSE RELATIONSHIP MAPPING:
        // Looks up rows in 'user_progress' matching the student's UUID, and pulls 
        // the corresponding 'title' from the 'quizzes' table so the app can display it.
        $history = UserProgress::with('quiz:id,title')
            ->where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $history->count(),
            'message' => 'Historical progress logs retrieved for data parsing.',
            'data' => $history
        ]);
    }

    /**
     * 5. DELETE (Reset Attempt) - Wipe a progress entry if a student requires a retake.
     * Target Frontend: Professor Dashboard / Admin panel reset option
     * DELETE /api/v1/curriculum/progress/{id}
     */
    public function destroyProgress(string $id): JsonResponse
    {
        // Searches for the specific record row inside the 'user_progress' table
        $progress = UserProgress::find($id);

        if (!$progress) {
            return response()->json([
                'success' => false,
                'message' => 'Progress log entry not found in active matrices.'
            ], 404);
        }

        // Drops the record row from PostgreSQL
        $progress->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quiz performance record safely cleared from system memory.'
        ]);
    }

    /**
     * GENERATE AI PERSONALIZED DASHBOARD RECOMMENDATIONS (TAB 1)
     * GET /api/v1/curriculum/dashboard-recommendations
     */
    public function getDashboardRecommendations(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Fetch the user's entire score history alongside the associated quiz details
        $progressRecords = UserProgress::where('user_id', $user->id)
            ->with('quiz') // Eager load the quiz relationships
            ->get();

        // Fallback if the user is brand new and hasn't taken any quizzes yet
        if ($progressRecords->isEmpty()) {
            return response()->json([
                'success' => true,
                'focus_area' => 'General Foundations',
                'recommendation' => "Welcome to Aura! Tap into the Grades tab to take your very first Treble Clef quiz so I can begin mapping your personalized learning path."
            ]);
        }

        // 2. Format a clean text summary of their current scores to feed into Gemini as context
        $scoreSummary = "";
        foreach ($progressRecords as $record) {
            if ($record->quiz) {
                $scoreSummary .= "- Topic: {$record->quiz->title}, Current Score: {$record->score}%\n";
            }
        }

        // 3. Craft the strict, lightweight prompt for Gemini
        $prompt = "You are Aura, an elite AI Music Professor. Analyze this student's raw performance data:\n\n"
            . $scoreSummary . "\n"
            . "Identify the single category where they are struggling the most (lowest score). "
            . "Generate a hyper-focused, encouraging, exactly 2-sentence study recommendation advising them what to do next. "
            . "Do not use markdown formatting or bullet points. Respond strictly in a clean JSON format with two keys: "
            . "'focus_area' (the topic name) and 'recommendation' (the 2-sentence advice text).";

        try {
            // 4. Hit the Gemini API gateway secure channel
            $apiKey = env('GEMINI_API_KEY');
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]]
                        ],
                        // Request clean JSON structures specifically
                        'generationConfig' => [
                            'responseMimeType' => 'application/json'
                        ]
                    ]);

            if ($response->failed()) {
                throw new \Exception("Gemini API communication dropped.");
            }

            // 5. Decode the raw nested response blocks out of Gemini's JSON payload
            $resultText = $response->json()['candidates'][0]['content']['parts'][0]['text'];
            $data = json_decode($resultText, true);

            return response()->json([
                'success' => true,
                'focus_area' => $data['focus_area'] ?? 'Review Needed',
                'recommendation' => $data['recommendation'] ?? 'Keep practicing your current curriculum modules.'
            ], 200);

        } catch (\Exception $e) {
            // Safe structural fallback if API network hits an outage
            return response()->json([
                'success' => false,
                'focus_area' => 'General Maintenance',
                'recommendation' => 'Continue reviewing your lowest scoring grade modules to stabilize your overall syllabus track profile.'
            ], 500);
        }
    }
}