<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TutorConversation; // Manages the 'tutor_conversations' table
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

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
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid'
        ]);

        $userId = $request->input('user_id');
        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id') ?? (string) Str::uuid();

        // STEP 1: Log the user's incoming message to PostgreSQL
        $userConversationLog = TutorConversation::create([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
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
            'conversation_id' => $conversationId,
            'message_type' => 'ai',
            'content' => $aiOutput
        ]);

        return response()->json([
            'success' => true,
            'response' => $aiOutput,
            'conversation_id' => $conversationId,
            'user_log' => $userConversationLog,
            'log' => $aiConversationLog
        ], 201);
    }

    /**
     * 2. READ (Conversation Index) - List saved chat threads for the tutor screen.
     * Target Frontend: Tutor Screen conversation picker.
     * GET /api/v1/tutor/conversations?user_id={id}
     */
    public function conversations(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        $conversations = TutorConversation::query()
            ->where('user_id', $request->query('user_id'))
            ->whereNotNull('conversation_id')
            ->selectRaw('conversation_id, MIN(created_at) as created_at, MAX(created_at) as updated_at, COUNT(*) as message_count')
            ->groupBy('conversation_id')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($conversation) use ($request) {
                $firstMessage = TutorConversation::query()
                    ->where('user_id', $request->query('user_id'))
                    ->where('conversation_id', $conversation->conversation_id)
                    ->orderBy('created_at', 'asc')
                    ->value('content');

                return [
                    'conversation_id' => $conversation->conversation_id,
                    'title' => Str::limit($firstMessage ?? 'New conversation', 80),
                    'message_count' => (int) $conversation->message_count,
                    'created_at' => $conversation->created_at,
                    'updated_at' => $conversation->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'count' => $conversations->count(),
            'data' => $conversations,
        ]);
    }

    /**
     * 2. READ (Index / History) - Pull past chat logs for a clean messaging screen.
     * Target Frontend: Tutor Screen (Loads existing thread history when opened)
     * GET /api/v1/tutor/history?user_id={id}&conversation_id={uuid}
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'conversation_id' => 'nullable|uuid'
        ]);

        $query = TutorConversation::where('user_id', $request->query('user_id'));

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->query('conversation_id'));
        }

        // Fetch logs sorted chronologically so the oldest messages appear at the top
        $logs = $query
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs
        ]);
    }

    /**
     * 3. DELETE (Destroy Thread) - Delete one saved conversation by ID.
     * Target Frontend: Chat history list item delete action.
     * DELETE /api/v1/tutor/conversations/{conversationId}?user_id={id}
     */
    public function deleteConversation(Request $request, string $conversationId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        if (!Str::isUuid($conversationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid conversation identifier format.'
            ], 422);
        }

        $deleted = TutorConversation::query()
            ->where('user_id', $request->query('user_id'))
            ->where('conversation_id', $conversationId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found for this user.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted successfully.',
            'deleted_count' => $deleted,
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * 4. DELETE (Destroy All Threads) - Clear all conversation logs for a user.
     * Target Frontend: Settings Panel / "Clear all chats" action.
     * DELETE /api/v1/tutor/conversations?user_id={id}
     */
    public function clearConversations(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        $deleted = TutorConversation::query()
            ->where('user_id', $request->query('user_id'))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation data securely cleared from relational logs.',
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Backward-compatible alias for existing frontend clients.
     * DELETE /api/v1/tutor/history?user_id={id}&conversation_id={uuid}
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'conversation_id' => 'nullable|uuid'
        ]);

        if ($request->filled('conversation_id')) {
            return $this->deleteConversation($request, $request->query('conversation_id'));
        }

        return $this->clearConversations($request);
    }
}