<?php

namespace App\Services;

use App\Models\User;

/**
 * The Pitch Accuracy research metric (Phase 0 item 4): mean absolute cents
 * deviation over a rolling window of the last N attempts. Shared by
 * AdaptiveSequencingService (which just needs the current snapshot against
 * its unlock threshold) and the accuracy-trend endpoint (which needs the
 * full trajectory for the actual improvement-over-time analysis).
 */
class PitchAccuracyService
{
    public const ROLLING_WINDOW_N = 10;

    /**
     * Mean |cents_deviation| over the most recent $n practice attempts (a
     * growing window until $n attempts exist). Null if the user has no
     * practice attempts yet.
     */
    public function currentRollingAverage(User $user, int $n = self::ROLLING_WINDOW_N): ?float
    {
        $attempts = $user->auralAttempts()
            ->where('context', 'practice')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($n)
            ->get();

        if ($attempts->isEmpty()) {
            return null;
        }

        return round($attempts->avg(fn ($attempt) => abs($attempt->cents_deviation)), 1);
    }

    /**
     * The full rolling-average trajectory across a user's entire practice
     * history - one point per attempt, each averaging that attempt plus up to
     * (n-1) prior ones. This is the actual research series (accuracy
     * improvement over time), not just a single current snapshot.
     */
    public function rollingTrend(User $user, int $n = self::ROLLING_WINDOW_N): array
    {
        $attempts = $user->auralAttempts()
            ->where('context', 'practice')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $trend = [];
        $window = [];

        foreach ($attempts as $index => $attempt) {
            $window[] = abs($attempt->cents_deviation);
            if (count($window) > $n) {
                array_shift($window);
            }

            $trend[] = [
                'attempt_number' => $index + 1,
                'created_at' => $attempt->created_at->toIso8601String(),
                'cents_deviation' => $attempt->cents_deviation,
                'rolling_avg_cents' => round(array_sum($window) / count($window), 1),
            ];
        }

        return $trend;
    }
}
