<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TutorConversation; // Manages the 'tutor_conversations' table
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;

class TutorController extends Controller
{
    /**
     * 1. CREATE (Store Chat & Fetch AI Response)
     * Target Frontend: Tutor Chat Interface (Submits a new question)
     * POST /api/v1/tutor/chat
     */
    public function chat(Request $request): JsonResponse
    {
        // Guard rails to make sure we have a valid student and string content
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'message' => 'required|string|max:5000'
        ]);

        $userId = $request->input('user_id');
        $userMessage = $request->input('message');

        // STEP 1: Log the user's incoming message to PostgreSQL
        TutorConversation::create([
            'user_id' => $userId,
            'message_type' => 'user',
            'content' => $userMessage
        ]);

        // STEP 2: Establish the AI's pedagogical constraints (System Prompt)
        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'GEMINI_API_KEY is not configured on the server.'
            ], 500);
        }

        $systemPrompt = "You are Aura, an elite AI Music Professor specializing in the ABRSM music theory curriculum. "
            . "Your job is to answer music theory questions clearly, concisely, and accurately. "
            . "Use markdown bullet points and visual text formatting where possible. "
            . "If a student asks something completely unrelated to music or audio, gently steer them back to music theory.";

        try {
            // STEP 3: Dispatch HTTP request to Google Gemini Gateway API
            // We inject the system persona right alongside the user's input question
            $response = Http::timeout(25)
                ->acceptJson()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => "System Context / Instructions:\n{$systemPrompt}\n\nStudent Question:\n{$userMessage}"]
                            ]
                        ]
                    ]
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'The AI Core Engine connection failed before receiving a response.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 502);
        }

        // Error handling if the external AI gateway fails
        if ($response->failed()) {
            $upstreamError = $response->json('error.message')
                ?? $response->json('error.status')
                ?? 'Unknown upstream error.';

            return response()->json([
                'success' => false,
                'message' => 'The AI Core Engine is temporarily unreachable. Please try again shortly.',
                'upstream_status' => app()->isLocal() ? $response->status() : null,
                'upstream_error' => app()->isLocal() ? $upstreamError : null,
            ], 502);
        }

        // Parse out the nested string reply from the Gemini API response structure
        $aiOutput = $response->json('candidates.0.content.parts.0.text')
            ?? "I am having trouble computing that musical structure right now.";

        // STEP 4: Log the AI's generated answer to PostgreSQL for long-term thread memory
        $aiConversationLog = TutorConversation::create([
            'user_id' => $userId,
            'message_type' => 'ai',
            'content' => $aiOutput
        ]);

        return response()->json([
            'success' => true,
            'response' => $aiOutput,
            'log' => $aiConversationLog
        ], 201);
    }

    /**
     * 2. READ (Index / History) - Pull past chat logs for a clean messaging screen.
     * Target Frontend: Tutor Screen (Loads existing thread history when opened)
     * GET /api/v1/tutor/history?user_id={uuid}
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        // Fetch logs sorted chronologically so the oldest messages appear at the top
        $logs = TutorConversation::where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs
        ]);
    }

    /**
     * 3. DELETE (Destroy Thread) - Clear the conversation logs for a user.
     * Target Frontend: Settings Panel / "Clear Chat" Option
     * DELETE /api/v1/tutor/history?user_id={uuid}
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        // Deletes all conversation rows bound to this specific user UUID
        TutorConversation::where('user_id', $request->query('user_id'))->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation data securely cleared from relational logs.'
        ]);
    }
}