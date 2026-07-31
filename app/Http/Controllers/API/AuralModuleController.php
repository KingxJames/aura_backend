<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralExercise;
use App\Models\AuralModuleAttempt;
use App\Models\Grade;
use App\Models\PulseMetreAuthoredQuestion;
use App\Models\PulseMetreClip;
use App\Models\PulseMetreSession;
use App\Services\AdaptiveSequencingService;
use App\Services\AuralExerciseGeneratorService;
use App\Services\GeminiNoteService;
use App\Services\TranscriptionScoringService;
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
    // The 5 modules this controller currently knows how to generate/grade.
    private const MODULE_TYPES = ['pulse_metre', 'echo_singing', 'spot_difference', 'musical_features', 'transcription'];

    // How close (in milliseconds) a user's tap has to land to an expected beat
    // to count as "on time" for the Pulse & Metre (1A) rhythm scoring.
    private const PULSE_METRE_TOLERANCE_MS = 150;

    public function __construct(
        private AuralExerciseGeneratorService $generator,
        private GeminiNoteService $geminiNotes,
        private AdaptiveSequencingService $adaptiveSequencing,
        private TranscriptionScoringService $transcriptionScoring
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

        if ($moduleType === 'transcription') {
            $unlockStatus = $this->adaptiveSequencing->unlockStatus($request->user());

            if (!$unlockStatus['unlocked']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transcription is not unlocked yet - keep practicing Aural pitch-matching to build audiation skill first.',
                    'unlock_status' => $unlockStatus,
                ], 403);
            }
        }

        $request->validate([
            'grade_id' => 'required|integer|exists:grades,id',
        ]);

        $grade = Grade::findOrFail($request->query('grade_id'));

        // Pulse & Metre runs a stateful 10-question, 4-phase progression (see
        // AuralExerciseGeneratorService::phaseForQuestionNumber()), so it
        // alone needs a session looked up first.
        $session = null;

        try {
            if ($moduleType === 'pulse_metre') {
                $session = PulseMetreSession::firstOrCreate(
                    ['user_id' => $request->user()->id, 'grade_id' => $grade->id, 'status' => 'active'],
                    ['question_number' => 1, 'correct_count' => 0]
                );
                $phase = AuralExerciseGeneratorService::phaseForQuestionNumber($session->question_number);

                // Authored content (hand-picked song + beat timestamps) wins if it
                // exists for this exact slot; otherwise fall back to the real-clip
                // catalog (listen_mcq) or procedural generation (the tap phases) -
                // nothing breaks while the authored bank is still being filled in.
                $authored = PulseMetreAuthoredQuestion::where('grade_id', $grade->id)
                    ->where('question_number', $session->question_number)
                    ->first();

                $payload = match (true) {
                    $authored !== null => $this->buildAuthoredPulseMetrePayload($authored, $phase),
                    $phase === 'listen_mcq' => $this->generateListenClipPayload(),
                    default => $this->generator->generatePulseMetre($grade->level_number, $phase),
                };
            } else {
                // Maps each module_type to its generator method. Kept as an explicit table
                // (rather than deriving the method name from the string) since 'echo_singing'
                // maps to generateEchoPhrase() - the names don't follow a 1:1 naming pattern.
                $generatorMethods = [
                    'echo_singing' => 'generateEchoPhrase',
                    'spot_difference' => 'generateSpotDifference',
                    'musical_features' => 'generateMusicalFeatures',
                    'transcription' => 'generateTranscription',
                ];
                $generatorMethod = $generatorMethods[$moduleType];
                $payload = $this->generator->{$generatorMethod}($grade->level_number);
            }
        } catch (InvalidArgumentException $e) {
            // Thrown by the generator when a grade level's rules aren't defined yet.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($session) {
            $payload['session_id'] = $session->id;
            $payload['question_number'] = $session->question_number;
        }

        $exercise = AuralExercise::create([
            'user_id' => $request->user()->id,
            'grade_id' => $grade->id,
            'module_type' => $moduleType,
            'payload_jsonb' => $payload,
        ]);

        $data = [
            'exercise_id' => $exercise->id,
            'grade_id' => $grade->id,
            'module_type' => $moduleType,
            ...$payload,
        ];

        if ($session) {
            $data['session_progress'] = [
                'question_number' => $session->question_number,
                'phase' => $payload['phase'],
                'total_questions' => 10,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * listen_mcq (Q1-3) plays a real, pre-recorded 2-bar clip instead of a
     * synthesized click track - unlike every other Aural exercise, this one
     * pulls from a seeded catalog (PulseMetreClip) rather than
     * AuralExerciseGeneratorService, since real audio can't be procedurally
     * generated. Throws (caught by generateExercise's existing try/catch,
     * 422) if no clip has been seeded yet for the chosen time signature.
     */
    private function generateListenClipPayload(): array
    {
        $timeSignature = random_int(0, 1) === 0 ? '2/4' : '3/4';
        $clip = PulseMetreClip::where('time_signature', $timeSignature)
            ->inRandomOrder()
            ->first();

        if (!$clip) {
            throw new InvalidArgumentException(
                "No Pulse & Metre listening clips have been seeded for {$timeSignature} yet."
            );
        }

        return array_merge([
            'module_type' => 'pulse_metre',
            'phase' => 'listen_mcq',
            'time_signature' => $timeSignature,
            'audio_url' => $clip->audioUrl(),
            'clip_label' => $clip->label,
            'ground_truth' => [
                'time_signature' => $timeSignature,
            ],
        ], $this->generator->listenMcqFields());
    }

    /**
     * Builds a Pulse & Metre payload from a hand-authored question (one
     * specific song + time signature + hand-timed beats, fixed to a specific
     * question_number - see PulseMetreAuthoredQuestion). Only beat_timestamps_ms
     * is genuinely author-supplied for the tap phases; downbeat_indices/
     * audible_beat_indices are derived the same way the procedural generator
     * derives them, via the exact same shared helpers, so grading (which reads
     * these fields generically off payload_jsonb) works identically regardless
     * of whether the exercise came from here or from the generator/clip catalog.
     */
    private function buildAuthoredPulseMetrePayload(PulseMetreAuthoredQuestion $question, string $phase): array
    {
        $data = $question->payload_jsonb;
        $timeSignature = $data['time_signature'];
        $beatsPerBar = $timeSignature === '2/4' ? 2 : 3;

        $payload = [
            'module_type' => 'pulse_metre',
            'phase' => $phase,
            'time_signature' => $timeSignature,
            'audio_url' => $question->audioUrl(),
            'ground_truth' => [
                'time_signature' => $timeSignature,
            ],
        ];

        if ($phase === 'listen_mcq') {
            return array_merge($payload, $this->generator->listenMcqFields());
        }

        $beatTimestampsMs = $data['beat_timestamps_ms'];
        $payload['beats_per_bar'] = $beatsPerBar;
        $payload['beat_timestamps_ms'] = $beatTimestampsMs;

        return match ($phase) {
            'downbeat_tap' => array_merge($payload, $this->generator->downbeatTapFields($beatTimestampsMs, $beatsPerBar)),
            'muted_bar_tap' => array_merge($payload, $this->generator->mutedBarTapFields($beatsPerBar)),
            'boss' => array_merge(
                $payload,
                $this->generator->mutedBarTapFields($beatsPerBar),
                $this->generator->listenMcqFields()
            ),
            default => throw new InvalidArgumentException("Unknown Pulse & Metre phase: {$phase}"),
        };
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
            'transcription' => $this->gradeTranscription($request, $auralExercise),
            default => response()->json(['success' => false, 'message' => 'Unsupported module type.'], 500),
        };
    }

    /**
     * 1A GRADING: Pulse & Metre
     * Dispatches to whichever of the 4 phase graders matches the exercise's
     * generated phase, logs the attempt, then advances the user's session to
     * the next question.
     */
    private function gradePulseMetre(Request $request, AuralExercise $exercise): JsonResponse
    {
        $phase = $exercise->payload_jsonb['phase'] ?? 'listen_mcq';

        $grading = match ($phase) {
            'listen_mcq' => $this->gradeListenPhase($request, $exercise),
            'downbeat_tap' => $this->gradeDownbeatPhase($request, $exercise),
            'muted_bar_tap' => $this->gradeMutedBarPhase($request, $exercise),
            'boss' => $this->gradeBossPhase($request, $exercise),
            default => null,
        };

        if ($grading === null) {
            return response()->json(['success' => false, 'message' => 'Unsupported Pulse & Metre phase.'], 500);
        }

        $this->logAttempt(
            $request,
            $exercise,
            $grading['user_response'],
            $grading['is_correct'],
            $grading['score_details']
        );

        return response()->json([
            'success' => true,
            'is_correct' => $grading['is_correct'],
            'correct_answer' => $grading['correct_answer'],
            'score_details' => $grading['score_details'],
            'session_progress' => $this->advancePulseMetreSession($exercise, $grading['is_correct']),
        ]);
    }

    /**
     * Phase 1 (Q1-3), "Pure Listening": just the "2 time or 3 time?" MCQ, no tapping.
     */
    private function gradeListenPhase(Request $request, AuralExercise $exercise): array
    {
        $request->validate([
            'selected_time_signature' => 'required|string',
        ]);

        $groundTruth = $exercise->payload_jsonb['ground_truth'];
        $selected = trim($request->input('selected_time_signature'));
        $isCorrect = $selected === $groundTruth['time_signature'];

        return [
            'user_response' => ['selected_time_signature' => $selected],
            'is_correct' => $isCorrect,
            'correct_answer' => $groundTruth['time_signature'],
            'score_details' => ['selected' => $selected, 'expected' => $groundTruth['time_signature']],
        ];
    }

    /**
     * Phase 2 (Q4-6), "Downbeat Sniper": tap only on beat 1 of each bar. Graded
     * purely on timing against the downbeats - no MCQ this round. Capping the
     * allowed tap count stops "just tap constantly" from gaming the tolerance check.
     */
    private function gradeDownbeatPhase(Request $request, AuralExercise $exercise): array
    {
        $request->validate([
            'tap_timestamps_ms' => 'required|array',
            'tap_timestamps_ms.*' => 'numeric',
        ]);

        $taps = $request->input('tap_timestamps_ms');
        $allBeats = $exercise->payload_jsonb['beat_timestamps_ms'];
        $downbeatTimestamps = array_map(fn ($i) => $allBeats[$i], $exercise->payload_jsonb['downbeat_indices']);

        $accuracy = $this->computeTapAccuracy($downbeatTimestamps, $taps);
        $isCorrect = $accuracy['beats_on_time'] === $accuracy['total_beats']
            && count($taps) <= count($downbeatTimestamps) + 1;

        return [
            'user_response' => ['tap_timestamps_ms' => $taps],
            'is_correct' => $isCorrect,
            'correct_answer' => ['downbeat_timestamps_ms' => $downbeatTimestamps],
            'score_details' => $accuracy,
        ];
    }

    /**
     * Phase 3 (Q7-9), "Muted Bar": bar 1's clicks are audible, bar 2 is silent.
     * The user must keep tapping through the silence from their internal clock;
     * graded on timing accuracy across every beat, including the muted ones.
     */
    private function gradeMutedBarPhase(Request $request, AuralExercise $exercise): array
    {
        $request->validate([
            'tap_timestamps_ms' => 'required|array',
            'tap_timestamps_ms.*' => 'numeric',
        ]);

        $taps = $request->input('tap_timestamps_ms');
        $allBeats = $exercise->payload_jsonb['beat_timestamps_ms'];

        $accuracy = $this->computeTapAccuracy($allBeats, $taps);
        $isCorrect = $accuracy['beats_on_time'] === $accuracy['total_beats'];

        return [
            'user_response' => ['tap_timestamps_ms' => $taps],
            'is_correct' => $isCorrect,
            'correct_answer' => ['beat_timestamps_ms' => $allBeats],
            'score_details' => $accuracy,
        ];
    }

    /**
     * Phase 4 (Q10), "Boss Level": the Muted Bar tap-through, immediately
     * followed by the "what time signature was that?" MCQ - both must be
     * correct, requiring the user to hold tempo and meter in memory at once.
     */
    private function gradeBossPhase(Request $request, AuralExercise $exercise): array
    {
        $request->validate([
            'tap_timestamps_ms' => 'required|array',
            'tap_timestamps_ms.*' => 'numeric',
            'selected_time_signature' => 'required|string',
        ]);

        $taps = $request->input('tap_timestamps_ms');
        $allBeats = $exercise->payload_jsonb['beat_timestamps_ms'];
        $accuracy = $this->computeTapAccuracy($allBeats, $taps);
        $timingCorrect = $accuracy['beats_on_time'] === $accuracy['total_beats'];

        $groundTruth = $exercise->payload_jsonb['ground_truth'];
        $selected = trim($request->input('selected_time_signature'));
        $mcqCorrect = $selected === $groundTruth['time_signature'];

        return [
            'user_response' => ['tap_timestamps_ms' => $taps, 'selected_time_signature' => $selected],
            'is_correct' => $timingCorrect && $mcqCorrect,
            'correct_answer' => [
                'beat_timestamps_ms' => $allBeats,
                'time_signature' => $groundTruth['time_signature'],
            ],
            'score_details' => array_merge($accuracy, [
                'timing_correct' => $timingCorrect,
                'mcq_correct' => $mcqCorrect,
            ]),
        ];
    }

    /**
     * Shared by the 3 tap-based phases: for each expected beat, finds the
     * closest tap and records the timing delta against PULSE_METRE_TOLERANCE_MS.
     */
    private function computeTapAccuracy(array $expectedBeats, array $taps): array
    {
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

        return [
            'tap_deltas_ms' => $deltas,
            'beats_on_time' => $onTimeCount,
            'total_beats' => count($expectedBeats),
            'timing_accuracy_pct' => count($expectedBeats) > 0
                ? round(($onTimeCount / count($expectedBeats)) * 100, 1)
                : 0,
        ];
    }

    /**
     * Advances the exercise's PulseMetreSession to the next question, marking it
     * completed once question 10 is answered (the next generateExercise() call
     * will then start a fresh session back at question 1). Returns a summary of
     * the question that was just answered, not the upcoming one.
     */
    private function advancePulseMetreSession(AuralExercise $exercise, bool $isCorrect): ?array
    {
        $sessionId = $exercise->payload_jsonb['session_id'] ?? null;
        $session = $sessionId ? PulseMetreSession::find($sessionId) : null;

        if (!$session) {
            return null;
        }

        $completedQuestionNumber = $session->question_number;
        $completedPhase = AuralExerciseGeneratorService::phaseForQuestionNumber($completedQuestionNumber);

        $session->correct_count += $isCorrect ? 1 : 0;
        $session->question_number += 1;

        if ($session->question_number > 10) {
            $session->status = 'completed';
        }

        $session->save();

        return [
            'question_number' => $completedQuestionNumber,
            'phase' => $completedPhase,
            'total_questions' => 10,
            'status' => $session->status,
            'correct_count' => $session->correct_count,
        ];
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

    /**
     * TRANSCRIPTION GRADING
     * Elapsed time is measured from exercise generation (server-authoritative
     * exercise->created_at) to this submission - not a client-supplied
     * timestamp, so it can't be gamed. Correctness uses note-sequence
     * alignment (see TranscriptionScoringService) rather than a positional
     * diff, so one skipped/extra note doesn't cascade into marking every
     * later note wrong.
     */
    private function gradeTranscription(Request $request, AuralExercise $exercise): JsonResponse
    {
        $request->validate([
            'note_sequence' => 'required|array|min:1',
            'note_sequence.*.note_name' => 'required|string',
            'note_sequence.*.octave' => 'required|integer',
            'note_sequence.*.duration_beats' => 'required|numeric',
        ]);

        $submitted = $request->input('note_sequence');
        $groundTruth = $exercise->payload_jsonb['ground_truth']['note_sequence'];

        $scoring = $this->transcriptionScoring->score($submitted, $groundTruth);
        $elapsedMs = $exercise->created_at->diffInMilliseconds(now());

        $scoreDetails = array_merge($scoring, [
            'elapsed_ms' => $elapsedMs,
            // Speed is only meaningful analysis data once correctness clears the
            // gate - a fast wrong answer must not register as progress.
            'speed_valid' => $scoring['correctness_pct'] >= TranscriptionScoringService::CORRECTNESS_THRESHOLD_PCT,
        ]);

        $attempt = $this->logAttempt($request, $exercise, ['note_sequence' => $submitted], $scoring['is_correct'], $scoreDetails);

        return response()->json([
            'success' => true,
            'attempt_id' => $attempt->id,
            'is_correct' => $scoring['is_correct'],
            'correct_answer' => $groundTruth,
            'score_details' => $scoreDetails,
        ]);
    }

    /**
     * TRANSCRIPTION UNLOCK STATUS
     * Lets the client show real progress ("3 more consistent attempts needed")
     * instead of a blind locked wall. Control arm always reports unlocked.
     * GET /api/v1/aural/modules/transcription/unlock-status
     */
    public function transcriptionUnlockStatus(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->adaptiveSequencing->unlockStatus($request->user()),
        ]);
    }

    /**
     * TRANSCRIPTION SPEED RESEARCH METRIC (Primary RQ: transcription speed) -
     * one point per ordinary (non-baseline) transcription attempt, in
     * chronological order, mirroring AuralController::accuracyTrend's shape
     * for the equivalent pitch-accuracy series. The pretest value to compare
     * this trend against lives on the user (baseline_transcription_elapsed_ms),
     * since the baseline item is a single one-off, not a trend.
     * GET /api/v1/aural/modules/transcription/speed-trend
     */
    public function transcriptionSpeedTrend(Request $request): JsonResponse
    {
        $attempts = AuralModuleAttempt::where('user_id', $request->user()->id)
            ->where('module_type', 'transcription')
            ->where('is_baseline', false)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $trend = $attempts->values()->map(fn ($attempt, $index) => [
            'attempt_number' => $index + 1,
            'created_at' => $attempt->created_at->toIso8601String(),
            'elapsed_ms' => $attempt->score_details['elapsed_ms'] ?? null,
            'correctness_pct' => $attempt->score_details['correctness_pct'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $trend,
        ]);
    }

    /**
     * POST-ROUND AI DEBRIEF (Aural Training > module results screen)
     * POST /api/v1/aural/modules/debrief
     * Mirrors QuizController::topicDebrief - round-level scoring only exists
     * on the client (the module's exercise loop), so session stats are
     * supplied in the request body rather than looked up server-side.
     */
    public function debrief(Request $request): JsonResponse
    {
        $request->validate([
            'module_type' => 'required|string|in:' . implode(',', self::MODULE_TYPES),
            'correct_count' => 'required|integer|min:0',
            'total_questions' => 'required|integer|min:1',
        ]);

        $moduleLabel = $this->humanizeModuleType($request->module_type);
        $prompt = "You are Aura, an elite AI Music Professor. A student just finished a practice round on the Aural "
            . "Training module '{$moduleLabel}': {$request->correct_count}/{$request->total_questions} correct. "
            . "Write a short, warm, exactly 2-sentence note reacting to this specific result and giving one concrete "
            . "ear-training tip for '{$moduleLabel}'. Do not use markdown formatting or bullet points. Respond "
            . "strictly in clean JSON with a single key 'message' containing the note text.";

        return $this->geminiNotes->generateNote($prompt, 'Nice work — keep practicing this module to sharpen your ear.');
    }

    /**
     * "ASK AURA" HELP FOR A STRUGGLING MODULE (Aural Training > module screen)
     * POST /api/v1/aural/modules/help
     * Mirrors QuizController::topicHelp.
     */
    public function help(Request $request): JsonResponse
    {
        $request->validate([
            'module_type' => 'required|string|in:' . implode(',', self::MODULE_TYPES),
            'attempts' => 'required|integer|min:1',
            'best_score_percent' => 'required|integer|min:0|max:100',
        ]);

        $moduleLabel = $this->humanizeModuleType($request->module_type);
        $prompt = "You are Aura, an elite AI Music Professor. A student is struggling with the Aural Training module "
            . "'{$moduleLabel}': {$request->attempts} attempts so far, best score {$request->best_score_percent}%. "
            . "Write a short, encouraging, exactly 2-sentence study tip focused specifically on what to listen for in "
            . "'{$moduleLabel}' before they try again. Do not use markdown formatting or bullet points. Respond "
            . "strictly in clean JSON with a single key 'message' containing the tip text.";

        return $this->geminiNotes->generateNote(
            $prompt,
            "Take a moment to focus your ear before your next attempt on {$moduleLabel} — you've got this."
        );
    }

    /**
     * Mirrors the frontend's module label lookup so Gemini prompts read
     * naturally (e.g. 'pulse_metre' -> 'Pulse & Metre') instead of raw
     * snake_case keys.
     */
    private function humanizeModuleType(string $moduleType): string
    {
        $labels = [
            'pulse_metre' => 'Pulse & Metre',
            'echo_singing' => 'Echo Singing',
            'spot_difference' => 'Spotting the Difference',
            'musical_features' => 'Musical Features',
            'transcription' => 'Transcription',
        ];

        return $labels[$moduleType] ?? ucwords(str_replace('_', ' ', $moduleType));
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
            $query->whereHas('auralExercise', fn($q) => $q->where('grade_id', $request->query('grade_id')));
        }

        $attempts = $query->get();

        return response()->json([
            'success' => true,
            'count' => $attempts->count(),
            'data' => $attempts,
        ]);
    }
}
