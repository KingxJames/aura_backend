<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRating extends Model
{
    protected $fillable = [
        'quiz_id',
        'question_id',
        'rating',
        'attempts',
    ];

    protected $casts = [
        'rating' => 'float',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
