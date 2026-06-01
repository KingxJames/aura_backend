<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuralAttempt extends Model
{
    use HasUuids;

    protected $table = 'aural_attempts';

    protected $fillable = [
        'user_id',
        'target_note',
        'detected_frequency',
        'cents_deviation',
        'feedback_text',
        'audio_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}