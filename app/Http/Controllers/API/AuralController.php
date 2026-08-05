<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AuralAttempt;
use App\Services\PitchAnalysisService;
use App\Services\GeminiNoteService;
use App\Services\PitchAccuracyService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class AuralController extends Controller
{
    // Multiple variants per category, picked at random each time, so the
    // control arm (fixed/static feedback, no LLM) doesn't repeat the exact
    // same sentence verbatim across the many attempts a real 4-6 week study
    // session generates. Still genuinely static/template-based - not
    // LLM-generated - so the control condition itself is unchanged.
    private const CANNED_FEEDBACK_CORRECT = [
        "Excellent ear! Your note singing is stable and well within standard vocal accuracy limits.",
        "Right on target - your pitch matched cleanly and held steady.",
        "Great job! That was a confident, accurate match.",
        "Nice and centered - your pitch landed right where it should.",
        "Well matched! Your ear is tracking that note reliably.",
    ];

    private const CANNED_FEEDBACK_SHARP = [
        "You are singing slightly sharp (pitched too high). Relax your vocal cords slightly to land on center pitch.",
        "That came out a touch sharp. Ease off the tension in your throat to settle onto the note.",
        "You're pitching a little high there. Try softening your breath support to bring it down.",
        "Slightly sharp this time - imagine easing the note downward rather than pushing up to it.",
        "A bit above the target pitch. Relaxing your jaw and throat can help you land centered.",
    ];

    private const CANNED_FEEDBACK_FLAT = [
        "You are singing slightly flat (pitched too low). Support your breath and lift the center tone slightly.",
        "That landed a bit flat. Give it a touch more breath support to lift the pitch up to center.",
        "You're pitching a little low there. Try lifting the tone slightly as you sustain it.",
        "Slightly flat this time - imagine reaching gently upward into the note rather than under it.",
        "A bit below the target pitch. A little more breath energy can help bring it up to center.",
    ];

    private const CANNED_WARMUP_CORRECT = [
        "Nice and steady! The app is tuned in.",
        "Right on the mark - great way to start.",
        "Spot on! You're warmed up and ready.",
        "Clean and centered - nice work.",
    ];

    private const CANNED_WARMUP_SHARP = [
        "A little sharp today - ease off the tension and try again tomorrow.",
        "Just a touch high. Relax into the note next time.",
        "Slightly above center - take a breath and settle in.",
    ];

    private const CANNED_WARMUP_FLAT = [
        "Almost there - take a breath and settle onto the note.",
        "Just a touch low today. A little more breath support will help.",
        "Slightly under center - lift the tone gently next time.",
    ];

    private function pickCannedFeedback(array $variants): string
    {
        return $variants[array_rand($variants)];
    }

    public function __construct(
        private PitchAnalysisService $pitchAnalysis,
        private GeminiNoteService $geminiNotes,
        private PitchAccuracyService $pitchAccuracy
    ) {
    }

    /**
     * PITCH ACCURACY RESEARCH METRIC (Phase 0 item 4): the rolling-window MAE
     * trajectory across this user's whole practice history - one point per
     * attempt, each averaging that attempt plus up to (N-1) prior ones. This is
     * the actual improvement-over-time series for analysis, not a single snapshot.
     * GET /api/v1/aural/accuracy-trend
     */
    public function accuracyTrend(Request $request): JsonResponse
    {
        $trend = $this->pitchAccuracy->rollingTrend($request->user());

        return response()->json([
            'success' => true,
            'rolling_window_n' => PitchAccuracyService::ROLLING_WINDOW_N,
            'data' => $trend,
        ]);
    }

    /**
     * 1. READ (Index) - Get all attempts for a specific student.
     * GET /api/v1/aural?user_id={uuid}
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        $attempts = AuralAttempt::where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $attempts->count(),
            'data' => $attempts
        ]);
    }

    /**
     * 2. CREATE (Store) - Upload vocal audio, execute Python DSP engine, and save results.
     * POST /api/v1/aural/analyze
     */
    /**
     * PROCESS AUDIO PITCH EVALUATION (TAB 4)
     * POST /api/v1/aural/analyze
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg,webm|max:10240', // webm is what browser MediaRecorder actually produces (Expo web)
            'target_note' => 'required|string|max:5', // e.g., "A4", "C4"
        ]);

        $user = $request->user();

        // 1. Permanently store the raw audio upload inside local isolated disk directory
        $filePath = $request->file('audio')->store('audio/vocal_tests');
        $absolutePath = storage_path('app/private/' . $filePath);

        // 2. Run the Python DSP engine against the uploaded clip
        $processingStartedAt = microtime(true);
        $result = $this->pitchAnalysis->analyze($absolutePath, $request->target_note);

        if (!$result || isset($result['success']) && !$result['success']) {
            return $this->pitchAnalysis->failureResponse($result);
        }

        // 4. Generate dynamic, pedagogical feedback based on performance variance bounds
        $dev = $result['cents_deviation'];

        if ($result['is_correct']) {
            $cannedFeedback = $this->pickCannedFeedback(self::CANNED_FEEDBACK_CORRECT);
        } else {
            $cannedFeedback = $this->pickCannedFeedback(
                $dev > 0 ? self::CANNED_FEEDBACK_SHARP : self::CANNED_FEEDBACK_FLAT
            );
        }

        if ($user->study_arm === 'experimental') {
            $prompt = "You are Aura, an elite AI Music Professor. A student just sang a note targeting {$request->target_note}. "
                . "Their detected pitch was {$result['detected_frequency']} Hz, a deviation of {$dev} cents "
                . "(positive = sharp/too high, negative = flat/too low). Correct: " . ($result['is_correct'] ? 'yes' : 'no') . ". "
                . "Write a short, warm, exactly 2-sentence diagnostic critique explaining specifically what they did with "
                . "their pitch and one concrete physical correction tip (breath support, vocal tension, etc). Use plain, "
                . "jargon-free language a beginner can follow. Do not use markdown formatting or bullet points. Respond "
                . "strictly in clean JSON with a single key 'message' containing the critique text.";

            $feedback = $this->geminiNotes->generateText($prompt, $cannedFeedback);
        } else {
            $feedback = $cannedFeedback;
        }

        $processingMs = (int) round((microtime(true) - $processingStartedAt) * 1000);

        // 5. Commit record metrics directly to PostgreSQL analytics table
        $attempt = AuralAttempt::create([
            'user_id' => $user->id,
            'audio_path' => $filePath,
            'target_note' => $request->target_note,
            'detected_frequency' => $result['detected_frequency'],
            'cents_deviation' => $result['cents_deviation'],
            'processing_ms' => $processingMs,
            'feedback_text' => $feedback
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vocal audio assessment complete.',
            'data' => $attempt
        ], 200);
    }

    /**
     * TUNING FORK (Daily Warm-Up) - Low-stakes single-note pitch check.
     * Always evaluated against Middle C; not user-configurable.
     * POST /api/v1/aural/warm-up
     */
    public function warmUp(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg,webm|max:10240', // webm is what browser MediaRecorder actually produces (Expo web)
        ]);

        $user = $request->user();
        $targetNote = 'C4';

        $filePath = $request->file('audio')->store('audio/vocal_tests');
        $absolutePath = storage_path('app/private/' . $filePath);

        $processingStartedAt = microtime(true);
        $result = $this->pitchAnalysis->analyze($absolutePath, $targetNote);

        if (!$result || isset($result['success']) && !$result['success']) {
            return $this->pitchAnalysis->failureResponse($result);
        }

        $dev = $result['cents_deviation'];

        if ($result['is_correct']) {
            $cannedFeedback = $this->pickCannedFeedback(self::CANNED_WARMUP_CORRECT);
        } else {
            $cannedFeedback = $this->pickCannedFeedback(
                $dev > 0 ? self::CANNED_WARMUP_SHARP : self::CANNED_WARMUP_FLAT
            );
        }

        if ($user->study_arm === 'experimental') {
            $prompt = "You are Aura, an elite AI Music Professor. A student just did their daily Tuning Fork "
                . "warm-up, singing against Middle C (C4). Their detected pitch was {$result['detected_frequency']} Hz, "
                . "a deviation of {$dev} cents (positive = sharp/too high, negative = flat/too low). Correct: "
                . ($result['is_correct'] ? 'yes' : 'no') . ". Write a short, warm, exactly 1-sentence reaction to this "
                . "quick daily check-in, in plain jargon-free language. Do not use markdown formatting or bullet points. "
                . "Respond strictly in clean JSON with a single key 'message' containing the reaction text.";

            $feedback = $this->geminiNotes->generateText($prompt, $cannedFeedback);
        } else {
            $feedback = $cannedFeedback;
        }

        $processingMs = (int) round((microtime(true) - $processingStartedAt) * 1000);

        $attempt = AuralAttempt::create([
            'user_id' => $user->id,
            'context' => 'warm_up',
            'audio_path' => $filePath,
            'target_note' => $targetNote,
            'processing_ms' => $processingMs,
            'detected_frequency' => $result['detected_frequency'],
            'cents_deviation' => $result['cents_deviation'],
            'feedback_text' => $feedback
        ]);

        $streak = $user->recordWarmUp();

        return response()->json([
            'success' => true,
            'message' => 'Tuning Fork warm-up complete.',
            'data' => [
                'attempt' => $attempt,
                'streak' => $streak,
            ]
        ], 200);
    }

    /**
     * TUNING FORK STATUS - Whether today's warm-up is already done, plus streak state.
     * Read-only; does not mutate the streak.
     * GET /api/v1/aural/warm-up/today
     */
    public function warmUpStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'completed_today' => $user->last_warm_up_date?->isToday() ?? false,
                'streak' => [
                    'current' => $user->warm_up_streak,
                    'longest' => $user->warm_up_longest_streak,
                ],
            ]
        ]);
    }

    /**
     * 3. READ (Show) - Fetch one single, specific historical pitch attempt.
     * GET /api/v1/aural/{id}
     */
    public function show(string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $attempt
        ]);
    }

    /**
     * 4. UPDATE - Edit/append custom notes or professor feedback to a record.
     * PUT/PATCH /api/v1/aural/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        $request->validate([
            'feedback_text' => 'required|string'
        ]);

        // Updates only the text column, keeping the objective physical calculations locked
        $attempt->update([
            'feedback_text' => $request->input('feedback_text')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aural metrics feedback notes updated.',
            'data' => $attempt
        ]);
    }

    /**
     * 5. DELETE - Nuke a recording asset and its database entry.
     * DELETE /api/v1/aural/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        // Delete the binary raw file asset off the server disk
        if ($attempt->audio_path) {
            Storage::delete($attempt->audio_path);
        }

        // Delete the row configuration inside PostgreSQL
        $attempt->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vocal artifact securely removed from system arrays.'
        ]);
    }
}