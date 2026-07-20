<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralExercise;
use App\Models\AuralModuleAttempt;
use App\Models\Grade;
use App\Services\AuralExerciseGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * =========================================================================
 * GRADE-DIVIDED AURAL TRAINING MODULES (1A-1D)
 * =========================================================================
 * Unlike the Theory quizzes (Quiz.content_jsonb, a fixed pre-authored bank),
 * these exercises are generated fresh on every request by
 * AuralExerciseGeneratorService - so there's no seeded content to browse,
 * only a "give me a new exercise" endpoint and a "grade my answer" endpoint.
 */
class AuralModuleController extends Controller
{
    // The 4 modules this controller currently knows how to generate/grade.
    private const MODULE_TYPES = ['pulse_metre', 'echo_singing', 'spot_difference', 'musical_features'];

    // How close (in milliseconds) a user's tap has to land to an expected beat
    // to count as "on time" for the Pulse & Metre (1A) rhythm scoring.
    private const PULSE_METRE_TOLERANCE_MS = 150;

    public function __construct(
        private AuralExerciseGeneratorService $generator
    ) {
    }

    /**
     * 1. GENERATE A NEW EXERCISE
     * GET /api/v1/aural/modules/{moduleType}/exercise?grade_id={id}
     */
    public function generateExercise(Request $request, string $moduleType): JsonResponse
    {
        if (!in_array($moduleType, self::MODULE_TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown Aural module type: ' . $moduleType,
            ], 404);
        }

        $request->validate([
            'grade_id' => 'required|integer|exists:grades,id',
        ]);

        $grade = Grade::findOrFail($request->query('grade_id'));

        // Maps each module_type to its generator method. Kept as an explicit table
        // (rather than deriving the method name from the string) since 'echo_singing'
        // maps to generateEchoPhrase() - the names don't follow a 1:1 naming pattern.
        $generatorMethods = [
            'pulse_metre' => 'generatePulseMetre',
            'echo_singing' => 'generateEchoPhrase',
            'spot_difference' => 'generateSpotDifference',
            'musical_features' => 'generateMusicalFeatures',
        ];

        try {
            $generatorMethod = $generatorMethods[$moduleType];
            $payload = $this->generator->{$generatorMethod}($grade->level_number);
        } catch (InvalidArgumentException $e) {
            // Thrown by the generator when a grade level's rules aren't defined yet.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $exercise = AuralExercise::create([
            'user_id' => $request->user()->id,
            'grade_id' => $grade->id,
            'module_type' => $moduleType,
            'payload_jsonb' => $payload,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'exercise_id' => $exercise->id,
                'grade_id' => $grade->id,
                'module_type' => $moduleType,
                ...$payload,
            ],
        ]);
    }

    /**
     * 2. SUBMIT AN ATTEMPT FOR GRADING
     * POST /api/v1/aural/modules/exercises/{auralExercise}/attempt
     */
    public function submitAttempt(Request $request, AuralExercise $auralExercise): JsonResponse
    {
        $user = $request->user();

        // A user should only ever be able to submit against their own generated exercise.
        if ($auralExercise->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This exercise does not belong to you.',
            ], 403);
        }

        return match ($auralExercise->module_type) {
            'pulse_metre' => $this->gradePulseMetre($request, $auralExercise),
            'spot_difference' => $this->gradeSpotDifference($request, $auralExercise),
            'musical_features' => $this->gradeMusicalFeatures($request, $auralExercise),
            'echo_singing' => $this->recordEchoSingingStub($request, $auralExercise),
            default => response()->json(['success' => false, 'message' => 'Unsupported module type.'], 500),
        };
    }

    /**
     * 1A GRADING: Pulse & Metre
     * Scores tap timing against the expected beat grid, then checks the
     * follow-up "2 time or 3 time?" answer, which is what actually decides
     * is_correct.
     */
    private function gradePulseMetre(Request $request, AuralExercise $exercise): JsonResponse
    {
        $request->validate([
            'tap_timestamps_ms' => 'required|array',
            'tap_timestamps_ms.*' => 'numeric',
            'selected_time_signature' => 'required|string',
        ]);

        $expectedBeats = $exercise->payload_jsonb['beat_timestamps_ms'];
        $taps = $request->input('tap_timestamps_ms');

        // For each expected beat, find the closest tap and record the timing delta.
        // This is purely diagnostic feedback - it does not affect is_correct.
        $deltas = [];
        $onTimeCount = 0;
        foreach ($expectedBeats as $expectedMs) {
            $closestDelta = null;
            foreach ($taps as $tapMs) {
                $delta = abs($tapMs - $expectedMs);
                if ($closestDelta === null || $delta < $closestDelta) {
                    $closestDelta = $delta;
                }
            }
            $deltas[] = $closestDelta;
            if ($closestDelta !== null && $closestDelta < self::PULSE_METRE_TOLERANCE_MS) {
                $onTimeCount++;
            }
        }

        $groundTruth = $exercise->payload_jsonb['ground_truth'];
        $isCorrect = trim($request->input('selected_time_signature')) === $groundTruth['time_signature'];

        $scoreDetails = [
            'tap_deltas_ms' => $deltas,
            'beats_on_time' => $onTimeCount,
            'total_beats' => count($expectedBeats),
            'timing_accuracy_pct' => count($expectedBeats) > 0
                ? round(($onTimeCount / count($expectedBeats)) * 100, 1)
                : 0,
        ];

        $this->logAttempt($request, $exercise, [
            'tap_timestamps_ms' => $taps,
            'selected_time_signature' => $request->input('selected_time_signature'),
        ], $isCorrect, $scoreDetails);

        return response()->json([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_answer' => $groundTruth['time_signature'],
            'score_details' => $scoreDetails,
        ]);
    }

    /**
     * 1C GRADING: Spotting the Difference
     * Simple direct comparison, same style as QuizController::submitQuiz.
     */
    private function gradeSpotDifference(Request $request, AuralExercise $exercise): JsonResponse
    {
        $request->validate([
            'selected_position' => 'required|string|in:beginning,end',
        ]);

        $groundTruth = $exercise->payload_jsonb['ground_truth'];
        $isCorrect = $request->input('selected_position') === $groundTruth['position'];

        $scoreDetails = ['selected' => $request->input('selected_position'), 'expected' => $groundTruth['position']];

        $this->logAttempt($request, $exercise, [
            'selected_position' => $request->input('selected_position'),
        ], $isCorrect, $scoreDetails);

        return response()->json([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_answer' => $groundTruth['position'],
            'score_details' => $scoreDetails,
        ]);
    }

    /**
     * 1D GRADING: Musical Features
     * Two-part MCQ (dynamic + articulation) - both must be correct for is_correct.
     */
    private function gradeMusicalFeatures(Request $request, AuralExercise $exercise): JsonResponse
    {
        $request->validate([
            'selected_dynamic' => 'required|string|in:forte,piano',
            'selected_articulation' => 'required|string|in:legato,staccato',
        ]);

        $groundTruth = $exercise->payload_jsonb['ground_truth'];
        $dynamicCorrect = $request->input('selected_dynamic') === $groundTruth['dynamic'];
        $articulationCorrect = $request->input('selected_articulation') === $groundTruth['articulation'];
        $isCorrect = $dynamicCorrect && $articulationCorrect;

        $scoreDetails = [
            'dynamic_correct' => $dynamicCorrect,
            'articulation_correct' => $articulationCorrect,
        ];

        $this->logAttempt($request, $exercise, [
            'selected_dynamic' => $request->input('selected_dynamic'),
            'selected_articulation' => $request->input('selected_articulation'),
        ], $isCorrect, $scoreDetails);

        return response()->json([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_answer' => $groundTruth,
            'score_details' => $scoreDetails,
        ]);
    }

    /**
     * 1B STUB: Echo Singing
     * Multi-note phrase-matching DSP isn't built yet (it needs the Python pitch
     * analyzer extended to segment a recording into several notes, not just one -
     * see PitchAnalysisService). For now we just accept and store the recording
     * so nothing is lost once real grading lands; is_correct stays null rather
     * than us pretending to have scored it.
     */
    private function recordEchoSingingStub(Request $request, AuralExercise $exercise): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg|max:10240',
        ]);

        $filePath = $request->file('audio')->store('audio/echo_singing');

        $this->logAttempt($request, $exercise, [], null, null, $filePath);

        return response()->json([
            'success' => true,
            'is_correct' => null,
            'message' => 'Recording saved. Echo Singing scoring is not implemented yet - this attempt will need to be re-evaluated once phrase-matching DSP is built.',
        ]);
    }

    private function logAttempt(
        Request $request,
        AuralExercise $exercise,
        array $userResponse,
        ?bool $isCorrect,
        ?array $scoreDetails,
        ?string $audioPath = null
    ): AuralModuleAttempt {
        return AuralModuleAttempt::create([
            'user_id' => $request->user()->id,
            'aural_exercise_id' => $exercise->id,
            'module_type' => $exercise->module_type,
            'user_response' => $userResponse,
            'is_correct' => $isCorrect,
            'score_details' => $scoreDetails,
            'audio_path' => $audioPath,
        ]);
    }

    /**
     * 3. ATTEMPT HISTORY (Progress-by-grade view)
     * GET /api/v1/aural/modules/attempts?grade_id={id}&module_type={type}
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'grade_id' => 'nullable|integer|exists:grades,id',
            'module_type' => 'nullable|string|in:' . implode(',', self::MODULE_TYPES),
        ]);

        $query = AuralModuleAttempt::with('auralExercise:id,grade_id,module_type')
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('module_type')) {
            $query->where('module_type', $request->query('module_type'));
        }

        if ($request->filled('grade_id')) {
            $query->whereHas('auralExercise', fn ($q) => $q->where('grade_id', $request->query('grade_id')));
        }

        $attempts = $query->get();

        return response()->json([
            'success' => true,
            'count' => $attempts->count(),
            'data' => $attempts,
        ]);
    }
}
