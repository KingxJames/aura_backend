<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class AuralModuleAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'aural_exercise_id',
        'module_type',
        'user_response',
        'is_correct',
        'score_details',
        'audio_path',
    ];

    protected $casts = [
        'user_response' => 'array',
        'score_details' => 'array',
        'is_correct' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auralExercise(): BelongsTo
    {
        return $this->belongsTo(AuralExercise::class);
    }

    public function feedback(): MorphOne
    {
        return $this->morphOne(ExerciseFeedback::class, 'feedbackable');
    }
}
