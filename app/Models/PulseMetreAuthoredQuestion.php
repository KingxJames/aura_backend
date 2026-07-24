<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PulseMetreAuthoredQuestion extends Model
{
    protected $fillable = [
        'grade_id',
        'question_number',
        'payload_jsonb',
    ];

    protected $casts = [
        'payload_jsonb' => 'array',
    ];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * Public, web-servable URL for this question's audio file - shares the
     * same storage folder as PulseMetreClip (storage/app/public/audio/pulse_metre/).
     */
    public function audioUrl(): string
    {
        return Storage::url('audio/pulse_metre/' . $this->payload_jsonb['filename']);
    }
}
