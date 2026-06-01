<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AuralAttempt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class AuralController extends Controller
{
    /**
     * 1. READ (Index) - Get all attempts for a specific student.
     * GET /api/v1/aural?user_id={uuid}
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id'
        ]);

        $attempts = AuralAttempt::where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $attempts->count(),
            'data' => $attempts
        ]);
    }

    /**
     * 2. CREATE (Store) - Upload vocal audio, execute Python DSP engine, and save results.
     * POST /api/v1/aural/analyze
     */
    /**
     * PROCESS AUDIO PITCH EVALUATION (TAB 4)
     * POST /api/v1/aural/analyze
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg|max:10240', // Max 10MB clip
            'target_note' => 'required|string|max:5', // e.g., "A4", "C4"
        ]);

        $user = $request->user();

        // 1. Permanently store the raw audio upload inside local isolated disk directory
        $filePath = $request->file('audio')->store('audio/vocal_tests');
        $absolutePath = storage_path('app/private/' . $filePath);

        // 2. Build the terminal execution command targeting our Python script safely
        $scriptPath = base_path('pitch_analyzer.py');
        $command = "python3 " . escapeshellarg($scriptPath) . " " . escapeshellarg($absolutePath) . " " . escapeshellarg($request->target_note) . " 2>&1";

        // 3. Execute the shell script command and read output stream buffer
        $output = shell_exec($command);
        $result = json_decode($output, true);

        if (!$result || isset($result['success']) && !$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'DSP Engine processing failure.',
                'error' => $result['error'] ?? 'Malformed script output formatting.'
            ], 500);
        }

        // 4. Generate dynamic, pedagogical feedback based on performance variance bounds
        $feedback = "";
        $dev = $result['cents_deviation'];

        if ($result['is_correct']) {
            $feedback = "Excellent ear! Your note singing is stable and well within standard vocal accuracy limits.";
        } else {
            $feedback = $dev > 0
                ? "You are singing slightly sharp (pitched too high). Relax your vocal cords slightly to land on center pitch."
                : "You are singing slightly flat (pitched too low). Support your breath and lift the center tone slightly.";
        }

        // 5. Commit record metrics directly to PostgreSQL analytics table
        $attempt = AuralAttempt::create([
            'user_id' => $user->id,
            'audio_path' => $filePath,
            'target_note' => $request->target_note,
            'detected_frequency' => $result['detected_frequency'],
            'cents_deviation' => $result['cents_deviation'],
            'feedback_text' => $feedback
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vocal audio assessment complete.',
            'data' => $attempt
        ], 200);
    }

    /**
     * 3. READ (Show) - Fetch one single, specific historical pitch attempt.
     * GET /api/v1/aural/{id}
     */
    public function show(string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $attempt
        ]);
    }

    /**
     * 4. UPDATE - Edit/append custom notes or professor feedback to a record.
     * PUT/PATCH /api/v1/aural/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        $request->validate([
            'feedback_text' => 'required|string'
        ]);

        // Updates only the text column, keeping the objective physical calculations locked
        $attempt->update([
            'feedback_text' => $request->input('feedback_text')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aural metrics feedback notes updated.',
            'data' => $attempt
        ]);
    }

    /**
     * 5. DELETE - Nuke a recording asset and its database entry.
     * DELETE /api/v1/aural/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $attempt = AuralAttempt::find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Attempt log not found.'
            ], 404);
        }

        // Delete the binary raw file asset off the server disk
        if ($attempt->audio_path) {
            Storage::delete($attempt->audio_path);
        }

        // Delete the row configuration inside PostgreSQL
        $attempt->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vocal artifact securely removed from system arrays.'
        ]);
    }
}