<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\Expo\ExpoPushToken;

class PushTokenController extends Controller
{
    /**
     * Register (or reassign) a device's Expo push token to the authenticated user.
     * POST /api/v1/user/push-token
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', ExpoPushToken::rule()],
            'platform' => 'required|string|in:ios,android',
        ]);

        // Match by token alone (not scoped to the current user) so that a device
        // logging in as a different account correctly reassigns ownership of the
        // token, rather than colliding with the column's unique constraint.
        PushToken::updateOrCreate(
            ['token' => $request->token],
            ['user_id' => $request->user()->id, 'platform' => $request->platform],
        );

        return response()->json(['success' => true]);
    }

    /**
     * Remove a single device's push token from the authenticated user.
     * DELETE /api/v1/user/push-token
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $request->user()->pushTokens()->where('token', $request->token)->delete();

        return response()->json(['success' => true]);
    }
}
