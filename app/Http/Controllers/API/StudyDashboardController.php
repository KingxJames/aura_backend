<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralModuleAttempt;
use App\Models\User;
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
}
