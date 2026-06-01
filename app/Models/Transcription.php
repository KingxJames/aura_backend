<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'uploaded_image_url',
        'generated_musicxml',
        'generated_midi',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}