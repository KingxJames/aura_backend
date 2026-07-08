<?php

namespace App\Services;

class PitchAnalysisService
{
    /**
     * Shared DSP engine invocation - runs the Python pitch analyzer against a stored
     * audio clip and returns the decoded result array.
     */
    public function analyze(string $absolutePath, string $targetNote): ?array
    {
        $scriptPath = storage_path('app/script/pitch_analyzer.py');
        $command = "python3 " . escapeshellarg($scriptPath) . " " . escapeshellarg($absolutePath) . " " . escapeshellarg($targetNote) . " 2>&1";

        $output = shell_exec($command);

        return json_decode($output, true);
    }
}
