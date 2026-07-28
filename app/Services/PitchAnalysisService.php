<?php

namespace App\Services;

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
}
