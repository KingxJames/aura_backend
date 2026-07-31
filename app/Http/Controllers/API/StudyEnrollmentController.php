<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\StudyEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudyEnrollmentController extends Controller
{
    public function __construct(
        private StudyEnrollmentService $studyEnrollment
    ) {
    }

    /**
     * Whether the authenticated user is already enrolled, and whether they've
     * ever been shown the consent screen at all (regardless of whether they
     * joined) - lets the client skip the confirmation view and, separately,
     * decide whether a first-login redirect to the consent screen is still
     * needed. Both are tracked per-account, not per-device, so switching
     * devices (or a second account on the same device) behaves correctly.
     * Also reports baseline (pretest) status - enrolled participants must
     * finish the baseline assessment before normal arm-specific practice
     * becomes available. Does not return the assigned arm.
     * GET /api/v1/study/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'enrolled' => $user->study_arm !== null,
            'prompt_seen' => $user->study_prompt_seen_at !== null,
            'baseline_required' => $user->study_arm !== null && $user->baseline_completed_at === null,
            'baseline_completed' => $user->baseline_completed_at !== null,
        ]);
    }

    /**
     * Marks that the user has been shown the consent screen, independent of
     * whether they chose to join. Idempotent - safe to call every time the
     * consent screen mounts.
     * POST /api/v1/study/mark-prompt-seen
     */
    public function markPromptSeen(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->study_prompt_seen_at === null) {
            $user->study_prompt_seen_at = now();
            $user->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Enroll the authenticated user in the research study. A deliberate,
     * consent-gated second step distinct from account registration -- most
     * users never call this. Does not return the assigned arm: participants
     * must stay blinded to which condition they're in.
     */
    public function enroll(Request $request): JsonResponse
    {
        $request->validate([
            'consent' => 'required|accepted',
        ]);

        $user = $request->user();

        if ($user->study_arm !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Already enrolled in the study.',
            ], 409);
        }

        $user->study_arm = $this->studyEnrollment->assignArm();
        $user->study_enrolled_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Enrollment recorded.',
        ], 201);
    }
}
