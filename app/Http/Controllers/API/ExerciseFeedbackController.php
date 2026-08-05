<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralAttempt;
use App\Models\AuralModuleAttempt;
use App\Models\TutorConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseFeedbackController extends Controller
{
    // Keys here are the API-facing aliases (also registered as the Eloquent morph
    // map in AppServiceProvider) - never expose raw App\Models\* class names.
    private const MORPH_TYPES = [
        'aural_attempt' => AuralAttempt::class,
        'module_attempt' => AuralModuleAttempt::class,
        'tutor_message' => TutorConversation::class,
    ];

    /**
     * Submit (or update) post-exercise qualitative feedback - a 1-5 rating plus
     * an optional free-text comment - for one specific attempt. One feedback
     * record per attempt (ExerciseFeedback::feedbackable is a MorphOne on both
     * AuralAttempt and AuralModuleAttempt); resubmitting updates it in place
     * rather than creating a duplicate.
     * POST /api/v1/feedback
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'feedbackable_type' => 'required|string|in:' . implode(',', array_keys(self::MORPH_TYPES)),
            'feedbackable_id' => 'required|integer',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $modelClass = self::MORPH_TYPES[$request->input('feedbackable_type')];
        $attempt = $modelClass::find($request->input('feedbackable_id'));

        if (!$attempt || $attempt->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This attempt does not belong to you.',
            ], 403);
        }

        // Tutor messages are two-sided (the student's own question is one
        // "message" too) - only the AI's reply is something a clarity/jargon
        // rating makes sense against.
        if ($attempt instanceof TutorConversation && $attempt->message_type !== 'ai') {
            return response()->json([
                'success' => false,
                'message' => 'Only tutor replies can be rated.',
            ], 422);
        }

        // Calling updateOrCreate on the relation itself (not the ExerciseFeedback
        // model directly) so feedbackable_type/feedbackable_id are set correctly
        // via the morph map, instead of risking a raw class-name/alias mismatch.
        $feedback = $attempt->feedback()->updateOrCreate(
            [],
            [
                'user_id' => $user->id,
                'rating' => $request->input('rating'),
                'comment' => $request->input('comment'),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $feedback,
        ], 201);
    }
}
