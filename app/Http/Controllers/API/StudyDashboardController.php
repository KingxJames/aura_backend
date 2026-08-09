<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralModuleAttempt;
use App\Models\User;
use App\Services\PitchAccuracyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RESEARCHER-ONLY STUDY DASHBOARD (Phase 1: Enrollment/Attrition tracking).
 * Admin-gated (see EnsureIsAdmin) - this is aggregate/cross-participant data,
 * never reachable by an ordinary participant token. Does not reveal any
 * individual participant's arm to anyone but the researcher.
 */
class StudyDashboardController extends Controller
{
    public function __construct(private PitchAccuracyService $pitchAccuracy)
    {
    }

    // A "completed session" = a calendar day with at least one real practice
    // attempt (Free Practice or non-baseline Transcription) - mirrors the
    // daily-cadence concept already used by the warm-up streak and flow
    // check-in, since the app has no other notion of a discrete "session".
    private const SESSION_FLOOR = 5;

    /**
     * How many participants are enrolled per arm, against the recruitment
     * target/floor from Phase 3 item 2.
     * GET /api/v1/study/admin/enrollment-summary
     */
    public function enrollmentSummary(Request $request): JsonResponse
    {
        $controlCount = User::where('study_arm', 'control')->count();
        $experimentalCount = User::where('study_arm', 'experimental')->count();
        $total = $controlCount + $experimentalCount;

        return response()->json([
            'success' => true,
            'data' => [
                'control_count' => $controlCount,
                'experimental_count' => $experimentalCount,
                'total_enrolled' => $total,
                'target_min' => 30,
                'target_max' => 40,
                'floor_total' => 20,
                'floor_per_arm' => 10,
                'is_pilot_range' => $total < 30,
            ],
        ]);
    }

    /**
     * Per-participant completed-session counts, flagging anyone below the
     * inclusion floor so they can be followed up with early rather than
     * discovered at data-freeze time.
     * GET /api/v1/study/admin/attrition
     */
    public function attrition(Request $request): JsonResponse
    {
        $users = User::whereNotNull('study_arm')->get();

        $data = $users->map(function (User $user) {
            $practiceDates = $user->auralAttempts()
                ->where('context', 'practice')
                ->get(['created_at'])
                ->map(fn ($attempt) => $attempt->created_at->toDateString());

            $transcriptionDates = AuralModuleAttempt::where('user_id', $user->id)
                ->where('is_baseline', false)
                ->get(['created_at'])
                ->map(fn ($attempt) => $attempt->created_at->toDateString());

            $sessionsCompleted = $practiceDates->merge($transcriptionDates)->unique()->count();

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'study_arm' => $user->study_arm,
                'enrolled_at' => $user->study_enrolled_at?->toIso8601String(),
                'sessions_completed' => $sessionsCompleted,
                'at_risk' => $sessionsCompleted < self::SESSION_FLOOR,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'session_floor' => self::SESSION_FLOOR,
            'data' => $data,
        ]);
    }

    /**
     * Per-participant baseline (pretest) vs. current pitch + transcription
     * accuracy, so a researcher can see how each participant is actually
     * trending rather than just whether they're still showing up.
     * "Current" pitch accuracy reuses PitchAccuracyService's rolling window;
     * "current" transcription accuracy averages the same-size window of
     * non-baseline transcription attempts, for a comparable snapshot.
     * GET /api/v1/study/admin/progress
     */
    public function progress(Request $request): JsonResponse
    {
        $users = User::whereNotNull('study_arm')->get();

        $data = $users->map(function (User $user) {
            $baselineCompleted = $user->baseline_completed_at !== null;

            $currentPitchCents = $baselineCompleted
                ? $this->pitchAccuracy->currentRollingAverage($user)
                : null;

            $pitchImprovementPct = ($currentPitchCents !== null && $user->baseline_pitch_accuracy_cents > 0)
                ? round(
                    (($user->baseline_pitch_accuracy_cents - $currentPitchCents) / $user->baseline_pitch_accuracy_cents) * 100,
                    1
                )
                : null;

            $recentTranscriptionAttempts = $baselineCompleted
                ? AuralModuleAttempt::where('user_id', $user->id)
                    ->where('module_type', 'transcription')
                    ->where('is_baseline', false)
                    ->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->limit(PitchAccuracyService::ROLLING_WINDOW_N)
                    ->get()
                : collect();

            $currentTranscriptionPct = $recentTranscriptionAttempts->isNotEmpty()
                ? round($recentTranscriptionAttempts->avg(fn ($a) => $a->score_details['correctness_pct'] ?? 0), 1)
                : null;

            $transcriptionImprovementPts = ($currentTranscriptionPct !== null && $user->baseline_transcription_accuracy_pct !== null)
                ? round($currentTranscriptionPct - $user->baseline_transcription_accuracy_pct, 1)
                : null;

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'study_arm' => $user->study_arm,
                'baseline_completed' => $baselineCompleted,
                'pitch' => [
                    'baseline_cents' => $user->baseline_pitch_accuracy_cents,
                    'current_cents' => $currentPitchCents,
                    'improvement_pct' => $pitchImprovementPct,
                ],
                'transcription' => [
                    'baseline_pct' => $user->baseline_transcription_accuracy_pct,
                    'current_pct' => $currentTranscriptionPct,
                    'improvement_pts' => $transcriptionImprovementPts,
                ],
            ];
        })->values();

        return response()->json([
            'success' => true,
            'rolling_window_n' => PitchAccuracyService::ROLLING_WINDOW_N,
            'data' => $data,
        ]);
    }

    /**
     * Removes a participant account entirely (their attempts, feedback,
     * tokens, etc. cascade via FK constraints) - a research-data cleanup
     * tool for throwaway/test accounts (e.g. QA signups), not general user
     * management. Can't target another researcher or the caller's own
     * account, so it can't be used to deadmin the study or lock yourself
     * out.
     * DELETE /api/v1/study/admin/participants/{user}
     */
    public function deleteParticipant(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => "Can't delete your own account.",
            ], 422);
        }

        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => "Can't delete another researcher account.",
            ], 422);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }
}
