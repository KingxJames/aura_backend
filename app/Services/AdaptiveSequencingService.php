<?php

namespace App\Services;

use App\Models\User;

/**
 * Formerly a Gordon MLT-grounded gate: Transcription only unlocked once a
 * student demonstrated audiation competence via consistent pitch accuracy on
 * the Aural tab, for the experimental arm only (control arm was always
 * unlocked). Transcription is now free practice for both arms - this no
 * longer gates access, but still reports the experimental arm's audiation
 * progress metrics (current_avg_cents/threshold_cents/attempts_so_far) since
 * those remain useful research/dashboard signal independent of gating.
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
                'unlocked' => true,
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
        $masteryMet = $avgAbsCents <= self::AUDIATION_MASTERY_THRESHOLD_CENTS;

        return [
            'unlocked' => true,
            'reason' => $masteryMet ? 'mastery_threshold_met' : 'below_threshold',
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
