<?php

namespace App\Services;

/**
 * Sequence-alignment scoring (edit-distance family, the same technique behind
 * Word Error Rate in speech-recognition scoring) for a submitted note sequence
 * against a ground truth. Extracted out of AuralModuleController so the fixed
 * baseline (pretest) transcription item can be graded with the exact same
 * algorithm as ordinary Transcription attempts, without duplicating it.
 */
class TranscriptionScoringService
{
    // Minimum note-sequence alignment correctness for an attempt to count as "correct".
    public const CORRECTNESS_THRESHOLD_PCT = 80.0;

    /**
     * Costs: 0 for an exact pitch+duration match, 2 for a full mismatch or an
     * inserted/deleted note. Correctness % is normalized against the
     * worst-case cost so it's comparable across different-length sequences.
     */
    public function score(array $submitted, array $groundTruth): array
    {
        $m = count($submitted);
        $n = count($groundTruth);

        $dp = [];
        for ($i = 0; $i <= $m; $i++) {
            $dp[$i][0] = $i * 2;
        }
        for ($j = 0; $j <= $n; $j++) {
            $dp[0][$j] = $j * 2;
        }

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $subCost = $this->noteMismatchCost($submitted[$i - 1], $groundTruth[$j - 1]);
                $dp[$i][$j] = min(
                    $dp[$i - 1][$j - 1] + $subCost, // match / substitute
                    $dp[$i - 1][$j] + 2,             // deletion (extra submitted note)
                    $dp[$i][$j - 1] + 2              // insertion (missing note)
                );
            }
        }

        $totalCost = $dp[$m][$n];
        $maxPossibleCost = 2 * max($m, $n, 1);
        $correctnessPct = round(max(0.0, 1 - ($totalCost / $maxPossibleCost)) * 100, 1);

        return [
            'correctness_pct' => $correctnessPct,
            'alignment_cost' => $totalCost,
            'is_correct' => $correctnessPct >= self::CORRECTNESS_THRESHOLD_PCT,
        ];
    }

    /**
     * Pitch/octave only - rhythm is intentionally not scored here. Transcription
     * tests the same "did you hear the right note" skill as Free Practice
     * (pitch), not a separate rhythmic-dictation skill.
     */
    private function noteMismatchCost(array $submittedNote, array $groundTruthNote): int
    {
        $pitchMatch = ($submittedNote['note_name'] ?? null) === ($groundTruthNote['note_name'] ?? null)
            && ($submittedNote['octave'] ?? null) === ($groundTruthNote['octave'] ?? null);

        return $pitchMatch ? 0 : 2;
    }
}
