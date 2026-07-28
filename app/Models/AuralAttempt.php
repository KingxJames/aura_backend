<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuralAttempt extends Model
{

    protected $table = 'aural_attempts';

    protected $fillable = [
        'user_id',
        'context',
        'opus_level_id',
        'target_note',
        'detected_frequency',
        'cents_deviation',
        'processing_ms',
        'feedback_text',
        'audio_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opusLevel(): BelongsTo
    {
        return $this->belongsTo(OpusLevel::class);
    }
}