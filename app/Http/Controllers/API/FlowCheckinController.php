<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FlowCheckin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PEDAGOGICAL SUB-QUESTION 2 (flow / cognitive overload) check-in.
 * A short, un-validated custom instrument (not a formal psychometric scale) -
 * two quick 1-5 self-report items, shown at most once per calendar day, the
 * first time a participant finishes an Aural Training attempt that day.
 * Scoped to Aural Training only - deliberately not wired into the AI Tutor
 * chat ("theoretical discourse"), per the researcher's explicit scope call.
 */
class FlowCheckinController extends Controller
{
    /**
     * Whether today's check-in has already been submitted - lets the client
     * decide whether to show the prompt on a result screen at all.
     * GET /api/v1/flow-checkin/today
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();

        $done = $user->flowCheckins()
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        return response()->json([
            'success' => true,
            'done_today' => $done,
        ]);
    }

    /**
     * Idempotent per day - if one already exists for today, this just
     * confirms success rather than creating a second row, so a race between
     * the Free Practice and Transcription screens (both of which can trigger
     * this prompt) can't double-submit.
     * POST /api/v1/flow-checkin
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'absorption_rating' => 'required|integer|min:1|max:5',
            'challenge_rating' => 'required|integer|min:1|max:5',
        ]);

        $user = $request->user();

        $existing = $user->flowCheckins()
            ->whereDate('created_at', now()->toDateString())
            ->first();

        if ($existing) {
            return response()->json(['success' => true, 'message' => 'Already recorded today.']);
        }

        FlowCheckin::create([
            'user_id' => $user->id,
            'absorption_rating' => $request->input('absorption_rating'),
            'challenge_rating' => $request->input('challenge_rating'),
        ]);

        return response()->json(['success' => true, 'message' => 'Recorded.'], 201);
    }
}
