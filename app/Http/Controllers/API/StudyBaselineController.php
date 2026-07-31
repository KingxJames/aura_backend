<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralAttempt;
use App\Models\AuralExercise;
use App\Models\AuralModuleAttempt;
use App\Models\Grade;
use App\Services\AuralExerciseGeneratorService;
use App\Services\PitchAnalysisService;
use App\Services\TranscriptionScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRETEST / BASELINE ASSESSMENT (Phase 3 item 2, "Baseline Assessment").
 * Captures baseline pitch accuracy and transcription performance immediately
 * after study consent, before any arm-specific feedback or gating has ever
 * run for this user - the O1 in the O1 -> X -> O2 pretest-posttest design.
 *
 * Deliberately isolated from AuralController/AuralModuleController rather
 * than threading a "baseline mode" branch through their already-verified
 * practice/gating logic:
 *  - Pitch trials use a FIXED, identical note sequence for every participant
 *    (not user-picked or random), so this is a standardized instrument.
 *  - Feedback is a single neutral string, not the arm-branching canned/AI
 *    feedback in AuralController::store() - the pretest itself must not be
 *    the first dose of either condition's feedback style.
 *  - The transcription item bypasses AdaptiveSequencingService's unlock gate
 *    entirely (is_baseline flag) since gating is itself part of the
 *    experimental-arm treatment being measured against.
 *  - Pitch trials are tagged context='baseline' on aural_attempts, which
 *    PitchAccuracyService/AdaptiveSequencingService already filter out via
 *    their own where('context', 'practice') clauses - no risk of polluting
 *    the adaptive-sequencing rolling average or unlock count.
 */
class StudyBaselineController extends Controller
{
    // Matches PitchAccuracyService::ROLLING_WINDOW_N so the baseline snapshot
    // is directly comparable to the rolling-average metric used afterward.
    private const PITCH_TRIALS_REQUIRED = 10;

    // Fixed, identical for every participant - random/self-picked notes would
    // let students gravitate toward pitches they're already comfortable with,
    // quietly inflating the "baseline". Spans just over an octave twice.
    private const PITCH_TARGET_SEQUENCE = [
        'C4', 'E4', 'G4', 'D4', 'F4', 'A4', 'B4', 'C5', 'G4', 'E4',
    ];

    private const NEUTRAL_FEEDBACK = 'Recorded.';

    public function __construct(
        private PitchAnalysisService $pitchAnalysis,
        private AuralExerciseGeneratorService $generator,
        private TranscriptionScoringService $transcriptionScoring
    ) {
    }

    /**
     * GET /api/v1/study/baseline/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $pitchTrialsDone = min(
            $user->auralAttempts()->where('context', 'baseline')->count(),
            self::PITCH_TRIALS_REQUIRED
        );

        $transcriptionDone = AuralModuleAttempt::where('user_id', $user->id)
            ->where('module_type', 'transcription')
            ->where('is_baseline', true)
            ->exists();

        return response()->json([
            'success' => true,
            'completed' => $user->baseline_completed_at !== null,
            'pitch_trials_required' => self::PITCH_TRIALS_REQUIRED,
            'pitch_trials_done' => $pitchTrialsDone,
            'pitch_targets' => self::PITCH_TARGET_SEQUENCE,
            'transcription_done' => $transcriptionDone,
        ]);
    }

    /**
     * One trial at a time, in fixed sequence order - the next target note is
     * derived server-side from how many baseline trials already exist, not
     * client-supplied, so it can't be skipped or reordered.
     * POST /api/v1/study/baseline/pitch-attempt
     */
    public function pitchAttempt(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->baseline_completed_at !== null) {
            return response()->json(['success' => false, 'message' => 'Baseline already completed.'], 409);
        }

        $doneCount = $user->auralAttempts()->where('context', 'baseline')->count();
        if ($doneCount >= self::PITCH_TRIALS_REQUIRED) {
            return response()->json(['success' => false, 'message' => 'All baseline pitch trials already recorded.'], 409);
        }

        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg,webm|max:10240',
        ]);

        $targetNote = self::PITCH_TARGET_SEQUENCE[$doneCount];

        $filePath = $request->file('audio')->store('audio/vocal_tests');
        $absolutePath = storage_path('app/private/' . $filePath);

        // DSP latency (Technical Sub-Question 1) - measured the same way as
        // AuralController::store()/warmUp(), so baseline and ordinary practice
        // attempts are directly comparable.
        $processingStartedAt = microtime(true);
        $result = $this->pitchAnalysis->analyze($absolutePath, $targetNote);

        if (!$result || (isset($result['success']) && !$result['success'])) {
            return response()->json([
                'success' => false,
                'message' => 'DSP Engine processing failure.',
                'error' => $result['error'] ?? 'Malformed script output formatting.',
            ], 500);
        }

        $processingMs = (int) round((microtime(true) - $processingStartedAt) * 1000);

        AuralAttempt::create([
            'user_id' => $user->id,
            'context' => 'baseline',
            'target_note' => $targetNote,
            'detected_frequency' => $result['detected_frequency'],
            'cents_deviation' => $result['cents_deviation'],
            'processing_ms' => $processingMs,
            'audio_path' => $filePath,
            'feedback_text' => self::NEUTRAL_FEEDBACK,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'is_correct' => $result['is_correct'],
                'cents_deviation' => $result['cents_deviation'],
                'trial_number' => $doneCount + 1,
                'trials_required' => self::PITCH_TRIALS_REQUIRED,
            ],
        ]);
    }

    /**
     * Generates (or returns the already-generated) fixed baseline
     * transcription item - Grade 1 difficulty, same as where Transcription
     * normally starts, but not subject to the unlock gate.
     * GET /api/v1/study/baseline/transcription-exercise
     */
    public function transcriptionExercise(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = AuralExercise::where('user_id', $user->id)
            ->where('module_type', 'transcription')
            ->where('is_baseline', true)
            ->latest()
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'data' => array_merge(['exercise_id' => $existing->id], $existing->payload_jsonb),
            ]);
        }

        $grade = Grade::where('level_number', 1)->firstOrFail();
        $payload = $this->generator->generateTranscription(1);

        $exercise = AuralExercise::create([
            'user_id' => $user->id,
            'grade_id' => $grade->id,
            'module_type' => 'transcription',
            'payload_jsonb' => $payload,
            'is_baseline' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge(['exercise_id' => $exercise->id], $payload),
        ]);
    }

    /**
     * POST /api/v1/study/baseline/transcription-attempt
     */
    public function transcriptionAttempt(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'exercise_id' => 'required|integer|exists:aural_exercises,id',
            'note_sequence' => 'required|array|min:1',
            'note_sequence.*.note_name' => 'required|string',
            'note_sequence.*.octave' => 'required|integer',
            'note_sequence.*.duration_beats' => 'required|numeric',
        ]);

        $exercise = AuralExercise::findOrFail($request->input('exercise_id'));

        if ($exercise->user_id !== $user->id || !$exercise->is_baseline) {
            return response()->json(['success' => false, 'message' => 'Invalid baseline exercise.'], 403);
        }

        $alreadyAttempted = AuralModuleAttempt::where('aural_exercise_id', $exercise->id)
            ->where('is_baseline', true)
            ->exists();

        if ($alreadyAttempted) {
            return response()->json(['success' => false, 'message' => 'Baseline transcription already submitted.'], 409);
        }

        $submitted = $request->input('note_sequence');
        $groundTruth = $exercise->payload_jsonb['ground_truth']['note_sequence'];
        $scoring = $this->transcriptionScoring->score($submitted, $groundTruth);

        // Elapsed time (Primary RQ: transcription speed) - measured the same
        // way as AuralModuleController::gradeTranscription(), so the baseline
        // snapshot is directly comparable to later transcription attempts.
        $elapsedMs = $exercise->created_at->diffInMilliseconds(now());
        $scoreDetails = array_merge($scoring, [
            'elapsed_ms' => $elapsedMs,
            'speed_valid' => $scoring['correctness_pct'] >= TranscriptionScoringService::CORRECTNESS_THRESHOLD_PCT,
        ]);

        $attempt = AuralModuleAttempt::create([
            'user_id' => $user->id,
            'aural_exercise_id' => $exercise->id,
            'module_type' => 'transcription',
            'user_response' => ['note_sequence' => $submitted],
            'is_correct' => $scoring['is_correct'],
            'score_details' => $scoreDetails,
            'is_baseline' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'attempt_id' => $attempt->id,
                'is_correct' => $scoring['is_correct'],
                'correctness_pct' => $scoring['correctness_pct'],
                'correct_answer' => $groundTruth,
            ],
        ]);
    }

    /**
     * Finalizes the baseline: aggregates the fixed pitch trials + the one
     * transcription item into the two research metrics stored on the user,
     * then stamps baseline_completed_at so normal Free Practice/Transcription
     * (and their arm-specific feedback/gating) becomes available.
     * POST /api/v1/study/baseline/complete
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->baseline_completed_at !== null) {
            return response()->json(['success' => true, 'message' => 'Already completed.']);
        }

        $pitchAttempts = $user->auralAttempts()->where('context', 'baseline')->get();
        $transcriptionAttempt = AuralModuleAttempt::where('user_id', $user->id)
            ->where('module_type', 'transcription')
            ->where('is_baseline', true)
            ->first();

        if ($pitchAttempts->count() < self::PITCH_TRIALS_REQUIRED || !$transcriptionAttempt) {
            return response()->json(['success' => false, 'message' => 'Baseline assessment is not finished yet.'], 422);
        }

        $user->baseline_pitch_accuracy_cents = round($pitchAttempts->avg(fn ($a) => abs($a->cents_deviation)), 1);
        $user->baseline_transcription_accuracy_pct = $transcriptionAttempt->score_details['correctness_pct'] ?? null;
        $user->baseline_transcription_elapsed_ms = $transcriptionAttempt->score_details['elapsed_ms'] ?? null;
        $user->baseline_completed_at = now();
        $user->save();

        return response()->json(['success' => true, 'message' => 'Baseline assessment recorded.']);
    }
}
