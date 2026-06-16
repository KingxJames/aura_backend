<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TutorConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TutorController extends Controller
{
    /**
     * 1. CREATE (Store Chat, Fetch AI Response with full Thread History)
     * POST /api/v1/tutor/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid'
        ]);

        $userId = $request->input('user_id');
        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id') ?? (string) Str::uuid();

        // STEP 1: Log the user's incoming message
        $userConversationLog = TutorConversation::create([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'message_type' => 'user',
            'content' => $userMessage
        ]);

        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'GEMINI_API_KEY is not configured on the server.'
            ], 500);
        }

        // STEP 2: Fetch last 10 messages of history to provide full conversational context
        $historyLogs = TutorConversation::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'asc')
            ->take(11) // Includes the message we just saved
            ->get();

        // Format history into Gemini's expected contents structure
        $contents = [];
        foreach ($historyLogs as $log) {
            $contents[] = [
                // Gemini looks for 'user' and 'model' roles
                'role' => $log->message_type === 'ai' ? 'model' : 'user',
                'parts' => [
                    ['text' => $log->content]
                ]
            ];
        }

        // STEP 3: Define the Pedagogical System Constraints
        $systemPrompt = "You are Aura, an elite AI Music Professor specializing in the ABRSM music theory curriculum, western music history, and musicology. "
            . "Your job is to answer music questions clearly, concisely, and accurately. "
            . "Use markdown bullet points, bold headers, and structural formatting. "
            . "If a student asks something completely unrelated to music, art history, or audio, gently steer them back to music theory.";

        try {
            // STEP 4: Dispatch payload to Gemini API (Passing System Instructions cleanly)
            $response = Http::timeout(25)
                ->acceptJson()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemPrompt]
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

        if ($response->failed()) {
            $upstreamError = $response->json('error.message') ?? 'Unknown upstream error.';
            return response()->json([
                'success' => false,
                'message' => 'The AI Core Engine is temporarily unreachable.',
                'upstream_error' => app()->isLocal() ? $upstreamError : null,
            ], 502);
        }

        // Parse out the text reply safely
        $aiOutput = $response->json('candidates.0.content.parts.0.text')
            ?? "I am having trouble computing that musical structure right now.";

        // STEP 5: Log the AI's response to DB for long-term memory
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
     * 2. READ (Conversation Index) - Optimized to fix N+1 Database Query Bug
     * GET /api/v1/tutor/conversations?user_id={id}
     */
    public function conversations(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        $userId = $request->query('user_id');

        // We run a single, fast SQL query to group and find the original prompt title
        $conversations = TutorConversation::query()
            ->where('user_id', $userId)
            ->whereNotNull('conversation_id')
            ->selectRaw('
                conversation_id, 
                MIN(created_at) as created_at, 
                MAX(created_at) as updated_at, 
                COUNT(*) as message_count,
                (SELECT content FROM tutor_conversations as tc2 
                 WHERE tc2.conversation_id = tutor_conversations.conversation_id 
                 AND tc2.user_id = ? 
                 ORDER BY tc2.created_at ASC LIMIT 1) as first_message
            ', [$userId])
            ->groupBy('conversation_id')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($conversation) {
                return [
                    'conversation_id' => $conversation->conversation_id,
                    'title' => Str::limit($conversation->first_message ?? 'New conversation', 80),
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
     * 2. READ (History) - Pull past chat logs chronologically
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

        $logs = $query->orderBy('created_at', 'asc')->get();

        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs
        ]);
    }

    /**
     * 3. DELETE (Destroy Single Thread)
     */
    public function deleteConversation(Request $request, string $conversationId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        if (!Str::isUuid($conversationId)) {
            return response()->json(['success' => false, 'message' => 'Invalid UUID format.'], 422);
        }

        $deleted = TutorConversation::query()
            ->where('user_id', $request->query('user_id'))
            ->where('conversation_id', $conversationId)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted successfully.',
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * 4. DELETE (Destroy All Threads)
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
            'message' => 'All conversation records cleared.',
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Backward-compatible alias.
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