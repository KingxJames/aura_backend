<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'uploaded_image_url',
        'generated_musicxml',
        'generated_midi',
    ];

    /**
     * Get the user that owns the transcription history record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}