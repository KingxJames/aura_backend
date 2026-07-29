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
     * Whether the authenticated user is already enrolled - lets the client
     * skip straight to a confirmation view instead of showing the consent
     * form again on every visit. Does not return the assigned arm.
     * GET /api/v1/study/status
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'enrolled' => $request->user()->study_arm !== null,
        ]);
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
