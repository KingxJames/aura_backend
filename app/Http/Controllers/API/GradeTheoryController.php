<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GradeTheoryController extends Controller
{
    private string $mtbPath = 'datasets/music_theory_bench.json';
    private string $hummusPath = 'datasets/hummus_qa.json';
    private string $mmauPath = 'datasets/mmau_pro.json';

    /**
     * Fetch questions separated by user-friendly topics.
     * GET /api/theory/questions?dataset=mtb&topic=pitch
     */
    public function getQuestions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dataset' => 'required|string|in:mtb,hummus,mmau',
            'topic' => 'nullable|string|in:pitch,rhythm,scales,terms'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datasetType = $request->query('dataset');
        $targetTopic = $request->query('topic');

        $questions = $this->loadDataset($datasetType);

        if (empty($questions)) {
            return response()->json([
                'status' => 'error',
                'message' => "Dataset standard source file matching [{$datasetType}] not initialized."
            ], 404);
        }

        // Filters data down by Grade 1 and explicit topic categories
        $filtered = array_values(array_filter($questions, function ($item) use ($targetTopic) {
            $isGrade1 = isset($item['metadata']['difficulty']) && $item['metadata']['difficulty'] === 'grade_1';

            if (!$isGrade1) {
                return false;
            }

            if ($targetTopic) {
                return isset($item['metadata']['topic']) && strtolower($item['metadata']['topic']) === strtolower($targetTopic);
            }

            return true;
        }));

        return response()->json([
            'meta' => [
                'status' => 'success',
                'dataset_source' => $this->getDatasetRefName($datasetType),
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
     * Process student submissions and return dynamic explanations.
     * POST /api/theory/evaluate
     */
    public function evaluateAnswers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dataset' => 'required|string|in:mtb,hummus,mmau',
            'submissions' => 'required|array',
            'submissions.*.question_id' => 'required|integer',
            'submissions.*.selected_option' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datasetType = $request->input('dataset');
        $submissions = $request->input('submissions');
        $sourceQuestions = collect($this->loadDataset($datasetType));

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

    private function loadDataset(string $type): array
    {
        $pathMap = [
            'mtb' => $this->mtbPath,
            'hummus' => $this->hummusPath,
            'mmau' => $this->mmauPath
        ];

        $targetPath = $pathMap[$type];

        if (!Storage::exists($targetPath)) {
            return $this->getMockedAcademicDataset($type);
        }

        return json_decode(Storage::get($targetPath), true) ?? [];
    }

    private function getDatasetRefName(string $type): string
    {
        return [
            'mtb' => 'MusicTheoryBench (m-a-p)',
            'hummus' => 'HumMusQA (2026 Academic Release)',
            'mmau' => 'MMAU-Pro'
        ][$type] ?? 'Unknown';
    }

    private function getMockedAcademicDataset(string $type): array
    {
        // Static mock data mimicking Hugging Face dataset schemas for quick running
        if ($type === 'mtb') {
            return [
                [
                    'id' => 101,
                    'question' => 'Identify the pitch on the second line of the treble clef staff.',
                    'options' => ['E', 'G', 'B', 'D'],
                    'ground_truth' => 'G',
                    'explanation' => 'Using the lines acronym "Every Good Boy Deserves Football", line 2 corresponds to G.',
                    'metadata' => ['difficulty' => 'grade_1', 'topic' => 'pitch']
                ],
                [
                    'id' => 102,
                    'question' => 'What is the value of a Crotchet note in 4/4 time?',
                    'options' => ['4 beats', '2 beats', '1 beat', 'Half a beat'],
                    'ground_truth' => '1 beat',
                    'explanation' => 'In a simple time signature with a 4 at the bottom, a crotchet is worth 1 whole beat.',
                    'metadata' => ['difficulty' => 'grade_1', 'topic' => 'rhythm']
                ]
            ];
        }
        return [];
    }
}