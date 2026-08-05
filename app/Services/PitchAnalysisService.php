<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PitchAnalysisService
{
    /**
     * Shared DSP engine invocation - calls the persistent, supervisor-managed
     * pitch_service.py (storage/app/script/pitch_service.py) over HTTP instead of
     * shell_exec'ing a fresh python3 process per request. A fresh process pays
     * librosa/numba's import + JIT-compile cost on every single call (~9.6s
     * measured); the persistent service pays that cost once at container boot.
     */
    public function analyze(string $absolutePath, string $targetNote): ?array
    {
        $baseUrl = env('PITCH_ANALYSIS_API_URL', 'http://127.0.0.1:8001');
        $endpoint = rtrim($baseUrl, '/') . '/api/v1/analyze';

        $response = Http::attach(
            'audio',
            file_get_contents($absolutePath),
            basename($absolutePath)
        )->timeout(30)->post($endpoint, [
            'target_note' => $targetNote,
        ]);

        if ($response->failed()) {
            return ['success' => false, 'error' => 'Pitch analysis service error: ' . $response->status()];
        }

        return $response->json();
    }

    /**
     * Builds the error response for a failed/null analyze() result, shared
     * across every controller that calls analyze(). Distinguishes two very
     * different failure classes that pitch_analyzer.py can return:
     *
     *  - A `confidence` key present means the DSP ran fine and rejected the
     *    *recording* itself (too much background noise, clip too quiet/short
     *    to trust) - this is a normal outcome of imperfect mic input, not a
     *    server error, so it's a 422 with the specific, actionable reason
     *    (e.g. "try again somewhere quieter") surfaced as the top-level
     *    message the client actually displays.
     *  - No `confidence` key (or no result at all) means the script crashed,
     *    timed out, or returned malformed output - a genuine engine failure,
     *    so it stays a 500 with the old generic message.
     */
    public function failureResponse(?array $result): JsonResponse
    {
        if ($result && array_key_exists('confidence', $result)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
                'confidence' => $result['confidence'],
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => 'DSP Engine processing failure.',
            'error' => $result['error'] ?? 'Malformed script output formatting.',
        ], 500);
    }
}
