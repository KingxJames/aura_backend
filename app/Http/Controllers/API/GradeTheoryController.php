<?php

namespace App\Http\Controllers\API; // Matches your folder hierarchy exactly

use App\Models\Grade;
use App\Http\Controllers\Controller; // Imports the parent controller class
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GradeTheoryController extends Controller
{
    /**
     * Fetch questions dynamically from database via Grade 1 Quizzes.
     * GET /api/v1/theory/questions?topic=scales
     */
    public function getQuestions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'nullable|string|in:pitch,rhythm,scales,terms,time_signatures'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $targetTopic = $request->query('topic');

        // Look for the Grade 1 record and eager load its quizzes from the DB
        $grade1 = Grade::where('level_number', 1)->with('quizzes')->first();

        if (!$grade1 || $grade1->quizzes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => "No quiz datasets found initialized in the database for Grade 1. Please run your seeder."
            ], 404);
        }

        // Merge questions across your Grade 1 quizzes
        $allQuestions = [];
        foreach ($grade1->quizzes as $quiz) {
            if (is_array($quiz->content_jsonb)) {
                $allQuestions = array_merge($allQuestions, $quiz->content_jsonb);
            }
        }

        // Filter data down by the requested topic metadata
        $filtered = array_values(array_filter($allQuestions, function ($item) use ($targetTopic) {
            if ($targetTopic) {
                return isset($item['metadata']['topic']) && strtolower($item['metadata']['topic']) === strtolower($targetTopic);
            }
            return true;
        }));

        return response()->json([
            'meta' => [
                'status' => 'success',
                'total_extracted' => count($filtered),
                'filters_applied' => [
                    'grade' => 1,
                    'topic' => $targetTopic ?? 'all'
                ]
            ],
            'questions' => $filtered
        ], 200);
    }

    /**
     * Process student submissions against DB quiz contents and return explanations.
     * POST /api/v1/theory/evaluate
     */
    public function evaluateAnswers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'submissions' => 'required|array',
            'submissions.*.question_id' => 'required|integer',
            'submissions.*.selected_option' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $submissions = $request->input('submissions');

        // Fetch Grade 1 quizzes from DB to compile verification truth
        $grade1 = Grade::where('level_number', 1)->with('quizzes')->first();

        $allQuestions = [];
        if ($grade1) {
            foreach ($grade1->quizzes as $quiz) {
                if (is_array($quiz->content_jsonb)) {
                    $allQuestions = array_merge($allQuestions, $quiz->content_jsonb);
                }
            }
        }

        $sourceQuestions = collect($allQuestions);
        $results = [];
        $totalCorrect = 0;

        foreach ($submissions as $submission) {
            $question = $sourceQuestions->firstWhere('id', $submission['question_id']);

            if (!$question) {
                continue;
            }

            $isCorrect = trim($question['ground_truth']) === trim($submission['selected_option']);
            if ($isCorrect) {
                $totalCorrect++;
            }

            $results[] = [
                'question_id' => $submission['question_id'],
                'question_text' => $question['question'],
                'user_answer' => $submission['selected_option'],
                'correct_answer' => $question['ground_truth'],
                'is_correct' => $isCorrect,
                'hint' => $question['hint'] ?? null,
                'explanation' => $question['explanation'] ?? 'No context explanation available.'
            ];
        }

        return response()->json([
            'meta' => [
                'status' => 'evaluation_complete',
                'score' => [
                    'total_submitted' => count($submissions),
                    'total_correct' => $totalCorrect,
                    'percentage' => count($submissions) > 0 ? round(($totalCorrect / count($submissions)) * 100, 2) : 0
                ]
            ],
            'evaluation_matrix' => $results
        ], 200);
    }
}
