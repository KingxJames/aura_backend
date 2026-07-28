<?php

namespace App\Services;

use App\Models\User;

class StudyEnrollmentService
{
    /**
     * Block-randomized arm assignment: whichever arm currently has fewer
     * enrolled participants wins, so the split stays balanced throughout
     * recruitment rather than only in expectation. Ties are broken by coin flip.
     */
    public function assignArm(): string
    {
        $controlCount = User::where('study_arm', 'control')->count();
        $experimentalCount = User::where('study_arm', 'experimental')->count();

        if ($controlCount === $experimentalCount) {
            return random_int(0, 1) === 0 ? 'control' : 'experimental';
        }

        return $controlCount < $experimentalCount ? 'control' : 'experimental';
    }
}
