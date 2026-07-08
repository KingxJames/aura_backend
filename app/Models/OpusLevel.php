<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpusLevel extends Model
{
    protected $fillable = [
        'level_number',
        'title',
        'description',
        'target_notes',
        'tolerance_cents',
    ];

    protected $casts = [
        'target_notes' => 'array',
    ];

    public function auralAttempts(): HasMany
    {
        return $this->hasMany(AuralAttempt::class);
    }

    /**
     * Distinct target notes this user has successfully matched within this level's tolerance.
     */
    public function passedNotesFor(User $user): array
    {
        $matched = $this->auralAttempts()
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn (AuralAttempt $attempt) => abs($attempt->cents_deviation) <= $this->tolerance_cents)
            ->pluck('target_note')
            ->unique()
            ->values()
            ->all();

        return array_values(array_intersect($matched, $this->target_notes));
    }

    public function isCompletedBy(User $user): bool
    {
        return count($this->passedNotesFor($user)) >= count($this->target_notes);
    }
}
