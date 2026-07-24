<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Models\TutorConversation;
use App\Models\UserProgress;
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
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid'
        ]);

        $userId = $request->user()->id;
        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id') ?? (string) Str::uuid();

        // STEP 1: Log the user's incoming message
        $userConversationLog = TutorConversation::create([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'message_type' => 'user',
            'content' => $userMessage
        ]);

        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'GEMINI_API_KEY is not configured on the server.'
            ], 500);
        }

        // STEP 2: Fetch last 10 messages of history to provide full conversational context.
        // Order descending to get the MOST RECENT rows (including the message we just
        // saved above), then reverse back to chronological order for Gemini's contents
        // array - ordering ascending with take() here would grab the OLDEST messages
        // instead, silently dropping the current question once a conversation passes
        // 11 total messages.
        $historyLogs = TutorConversation::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->take(11)
            ->get()
            ->reverse()
            ->values();

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
        //
        // Visual/audio note tags: this is the ONE place that should define the
        // [[note:...]] tag contract for Gemini. It used to be duplicated (and
        // contradicted - this block previously told Gemini to embed markdown
        // image URLs, which the app's renderer strips on receipt) in the
        // mobile app's per-message wrapper too; keep the tag spec here only
        // so the frontend (lib/tutorContent.ts parser + components/tutor/NoteGlyph.tsx
        // renderer) and this prompt can't drift out of sync again.
        $systemPrompt = "You are Aura, an elite AI Music Professor specializing in the music theory curriculum, western music history, and musicology. "
            . "Your job is to answer music questions clearly, concisely, and accurately. "
            . "Use markdown bullet points, bold headers, and structural formatting. "
            . "Never use markdown image syntax (![]()) - you cannot generate real image URLs and the app strips any image tag before display. "
            . "When you describe how a note looks, where a pitch sits on the staff, or how it sounds, put a tag on its own line right after the description in the exact format [[note:TYPE]], or [[note:TYPE,pitch:PITCH]] when a specific pitch is relevant, or [[note:TYPE,pitch:PITCH,clef:CLEF]] to choose the clef. "
            . "CRITICAL FORMATTING RULE: a [[note:...]] or [[sequence:...]] tag is extracted and rendered separately from your surrounding text before display - never make it the content of a markdown list item (e.g. \"- [[sequence:...]]\" or \"* [[note:...]]\"), because the list marker is then left with nothing else on its line and renders as an empty, orphaned bullet point. Put the tag on its own plain line with no leading -, *, or number. Name what it shows (e.g. \"a major 2nd\") in the sentence immediately BEFORE the tag, never as a trailing label on a line after it. "
            . "Add \",play:true\" to any of those (e.g. [[note:quarter,pitch:C4,play:true]]) whenever actually hearing the pitch would help the student - the app renders the tag as a real staff with a tappable play button that sounds that exact pitch. "
            . "TYPE is one of: whole, half, quarter, eighth, sixteenth. PITCH is a letter A-G optionally followed by # or b then an octave number, e.g. C4 for middle C, F#5, Bb3. CLEF is treble (default) or bass. "
            . "Omit pitch when the question is only about note duration/appearance in general, not about a specific pitch. "
            . "For a rest (silence) instead of a sounded note, use [[note:TYPE,rest:true]] instead - e.g. [[note:quarter,rest:true]] for a quarter rest. Rests never take pitch, clef, or play, since they have no pitch and nothing to hear. "
            . "To show an articulation mark on a note, add \",artic:VALUE\" where VALUE is staccato, accent, or tenuto - e.g. [[note:quarter,pitch:C4,artic:staccato]]. This is how you show what a staccato dot, accent, or tenuto mark actually looks like on the staff; do not just describe it in prose and expect the reader to picture it. "
            . "To show a dynamic marking, add \",dynamic:VALUE\" where VALUE is pp, p, mp, mf, f, or ff - e.g. [[note:quarter,pitch:C4,dynamic:mf]]. "
            . "To show a fermata (hold), add \",ornament:fermata\" - e.g. [[note:half,pitch:G4,ornament:fermata]]. "
            . "artic, dynamic, ornament, and play can all be combined on the same tag if relevant, but never use any of them with rest:true. "
            . "A [[note:...]] tag can only render ONE note or rest - for a scale or a short phrase (2 to 8 notes), use "
            . "[[sequence:notes:TOKENS,clef:CLEF,play:true]] instead, e.g. [[sequence:notes:C4-q D4-q E4-q F4-q G4-h,clef:treble,play:true]]. "
            . "TOKENS is space-separated, each one PITCH-CODE (e.g. C4-q) or r-CODE for a rest (e.g. r-q). CODE is a single letter: "
            . "w=whole, h=half, q=quarter, e=eighth, s=sixteenth. clef applies to the whole sequence (treble default). play, if present, "
            . "plays every token in order (rests are silent gaps) via one tappable button. "
            . "[[sequence:...]] does NOT support pitch accidentals beyond what's in the pitch string itself, articulation, dynamics, ornaments, "
            . "chords (only one pitch per token - never write two pitches for the same token), or beaming - each token is always its own "
            . "independent note. If you need any of those, or more than 8 notes, describe it in words instead of forcing it into this tag. "
            . "Both [[note:...]] and [[sequence:...]] accept \",time:N/D\" to show a time signature (e.g. \",time:3/4\" or \",time:6/8\") and "
            . "\",key:N\" to show a key signature, where N is a SIGNED number of sharps/flats: positive = sharps, negative = flats, e.g. "
            . "\",key:3\" for A major (3 sharps) or \",key:-2\" for B-flat major (2 flats). Common mappings: C/A minor=key:0 (omit it), "
            . "G major=key:1, D major=key:2, A major=key:3, E major=key:4, F major=key:-1, B-flat major=key:-2, E-flat major=key:-3. "
            . "For a minor key, use its relative major's sharp/flat count (e.g. E minor = G major's key signature = key:1). "
            . "Both key and time apply once to the whole staff (drawn between the clef and the first note), never per-note. "
            . "If a student asks something completely unrelated to music, art history, or audio, gently steer them back to music theory.";

        // STEP 3b: Ground the answer in the student's own curriculum, so
        // terminology/difficulty matches what they're actually being taught
        // instead of generic Gemini knowledge. "Current grade" is inferred
        // from their most recently touched quiz (same signal the dashboard
        // recommendation feature already uses) - the chat has no explicit
        // grade selector of its own.
        $studentQuestion = $this->extractStudentQuestion($userMessage);
        $currentGrade = $this->resolveCurrentGrade($userId);
        $curriculumContext = $this->buildCurriculumContext($currentGrade, $studentQuestion);
        if ($curriculumContext !== '') {
            $systemPrompt .= "\n\n" . $curriculumContext;
        }

        $primaryModel = config('services.gemini.model', 'gemini-2.5-flash');
        $fallbackModel = config('services.gemini.fallback_model', 'gemini-3-flash-preview');
        $enableFallback = (bool) config('services.gemini.enable_fallback', true);

        try {
            $response = $this->dispatchGeminiRequest($apiKey, $primaryModel, $contents, $systemPrompt);

            if ($response->failed() && $enableFallback && $fallbackModel !== $primaryModel) {
                $fallbackResponse = $this->dispatchGeminiRequest($apiKey, $fallbackModel, $contents, $systemPrompt);
                $response = $fallbackResponse;
            }
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

    private function dispatchGeminiRequest(string $apiKey, string $model, array $contents, string $systemPrompt)
    {
        return Http::timeout(25)
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => $contents,
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemPrompt]
                    ]
                ]
            ]);
    }

    /**
     * Strips the mobile app's <aura_tutor_instructions>/<student_question>
     * wrapper (see buildTutorMessage() in the frontend's tutor.tsx) down to
     * just the student's actual words, for grade-resolution keyword matching
     * below - matching against the instruction boilerplate too would just
     * add noise to every match.
     */
    private function extractStudentQuestion(string $rawMessage): string
    {
        if (preg_match('/<student_question>(.*?)<\/student_question>/s', $rawMessage, $matches)) {
            return trim($matches[1]);
        }

        return $rawMessage;
    }

    /**
     * Infers which Grade the student is currently working through. The tutor
     * chat has no explicit grade selector, so this reuses the same signal
     * getDashboardRecommendations() relies on: whichever quiz they most
     * recently touched. A brand-new student with no UserProgress yet still
     * gets grounded at the lowest grade, rather than no grounding at all.
     */
    private function resolveCurrentGrade(int $userId): ?Grade
    {
        $mostRecentProgress = UserProgress::where('user_id', $userId)
            ->with('quiz.grade')
            ->orderBy('updated_at', 'desc')
            ->first();

        if ($mostRecentProgress && $mostRecentProgress->quiz && $mostRecentProgress->quiz->grade) {
            return $mostRecentProgress->quiz->grade;
        }

        return Grade::orderBy('level_number', 'asc')->first();
    }

    /**
     * Builds a "STUDENT CONTEXT" block grounding the tutor's answer in the
     * student's actual curriculum: the grade's syllabus framing always, plus
     * (when the student's question shares real keywords with the grade's own
     * question bank) a couple of matching Q&A snippets, so the tutor's
     * terminology and phrasing matches what this grade actually teaches
     * instead of generic Gemini knowledge. Deliberately NOT the whole
     * 100-question bank per grade - that would blow the prompt budget on
     * every single message for content that's mostly irrelevant to it.
     */
    private function buildCurriculumContext(?Grade $grade, string $studentQuestion): string
    {
        if (!$grade) {
            return '';
        }

        $context = "STUDENT CONTEXT: This student is currently working on \"{$grade->title}\" "
            . "(syllabus focus: {$grade->syllabus_focus}). Calibrate the complexity, terminology, and scope of "
            . "your answer to this grade level unless the student clearly asks about something else.";

        $quiz = $grade->quizzes()->first();
        if (!$quiz || empty($quiz->content_jsonb)) {
            return $context;
        }

        // Crude keyword overlap, not embeddings/vector search - the question
        // bank is only ~100 short items per grade, so a linear scan for
        // shared 4+ letter words is plenty and needs no extra infrastructure.
        $words = array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($studentQuestion)) ?: [],
            fn ($word) => strlen($word) >= 4
        ));

        if (empty($words)) {
            return $context;
        }

        $matches = collect($quiz->content_jsonb)
            ->map(function ($question) use ($words) {
                $haystack = strtolower(($question['question'] ?? '') . ' ' . ($question['metadata']['topic'] ?? ''));
                $score = 0;
                foreach ($words as $word) {
                    if (str_contains($haystack, $word)) {
                        $score++;
                    }
                }
                return ['question' => $question, 'score' => $score];
            })
            ->filter(fn ($item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(2);

        if ($matches->isEmpty()) {
            return $context;
        }

        $context .= " Here is how this grade's own curriculum explains related concepts - match this style and terminology where relevant:\n";
        foreach ($matches as $item) {
            $q = $item['question'];
            $context .= "- Q: {$q['question']} A: {$q['ground_truth']}. {$q['explanation']}\n";
        }

        return $context;
    }

    /**
     * 2. READ (Conversation Index) - Optimized to fix N+1 Database Query Bug
     * GET /api/v1/tutor/conversations
     */
    public function conversations(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

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
     * GET /api/v1/tutor/history?conversation_id={uuid}
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'conversation_id' => 'nullable|uuid'
        ]);

        $query = TutorConversation::where('user_id', $request->user()->id);

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
        if (!Str::isUuid($conversationId)) {
            return response()->json(['success' => false, 'message' => 'Invalid UUID format.'], 422);
        }

        $deleted = TutorConversation::query()
            ->where('user_id', $request->user()->id)
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
        $deleted = TutorConversation::query()
            ->where('user_id', $request->user()->id)
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
            'conversation_id' => 'nullable|uuid'
        ]);

        if ($request->filled('conversation_id')) {
            return $this->deleteConversation($request, $request->query('conversation_id'));
        }

        return $this->clearConversations($request);
    }
}
