<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class TutorConversation extends Model
{

    protected $table = 'tutor_conversations';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'message_type',
        'content',
        'embedding_vector',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feedback(): MorphOne
    {
        return $this->morphOne(ExerciseFeedback::class, 'feedbackable');
    }
}