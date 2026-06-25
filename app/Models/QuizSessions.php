<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizSessions extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'quiz_id',
        'current_streak',
        'lives_remaining',
        'difficulty_tier',
        'status',
        'total_questions_answered',
        'total_correct_answers',
    ];

    /**
     * Relationship: A quiz session belongs to a specific user/student.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship: A quiz session tracks progress against a specific static quiz template.
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}