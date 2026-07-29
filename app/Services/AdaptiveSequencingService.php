<?php

namespace App\Services;

use App\Models\User;

/**
 * Gordon MLT-grounded gate: Transcription (symbolic association) only unlocks
 * once a student has demonstrated audiation competence via consistent pitch
 * accuracy on the Aural tab. Experimental arm only - control arm gets fixed/
 * static sequencing (always unlocked), which is the actual "static app"
 * contrast this study is measuring against.
 */
class AdaptiveSequencingService
{
    private const AUDIATION_MASTERY_THRESHOLD_CENTS = 35.0;

    public function __construct(
        private PitchAccuracyService $pitchAccuracy
    ) {
    }

    public function unlockStatus(User $user): array
    {
        if ($user->study_arm !== 'experimental') {
            return [
                'unlocked' => true,
                'reason' => 'static_sequencing',
                'current_avg_cents' => null,
                'threshold_cents' => null,
                'attempts_so_far' => null,
            ];
        }

        $attemptCount = $user->auralAttempts()->where('context', 'practice')->count();
        $avgAbsCents = $this->pitchAccuracy->currentRollingAverage($user);

        if ($avgAbsCents === null) {
            return [
                'unlocked' => false,
                'reason' => 'no_attempts_yet',
                'current_avg_cents' => null,
                'threshold_cents' => self::AUDIATION_MASTERY_THRESHOLD_CENTS,
                'attempts_so_far' => 0,
            ];
        }

        // Growing window until PitchAccuracyService::ROLLING_WINDOW_N attempts
        // exist, then a fixed sliding window of the most recent N - so this is
        // meaningful from the student's very first attempt onward instead of
        // sitting blank for a week.
        $unlocked = $avgAbsCents <= self::AUDIATION_MASTERY_THRESHOLD_CENTS;

        return [
            'unlocked' => $unlocked,
            'reason' => $unlocked ? 'mastery_threshold_met' : 'below_threshold',
            'current_avg_cents' => $avgAbsCents,
            'threshold_cents' => self::AUDIATION_MASTERY_THRESHOLD_CENTS,
            'attempts_so_far' => min($attemptCount, PitchAccuracyService::ROLLING_WINDOW_N),
        ];
    }

    public function hasUnlockedTranscription(User $user): bool
    {
        return $this->unlockStatus($user)['unlocked'];
    }
}
