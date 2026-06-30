<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The "embedding" column is a pgvector type with no native Eloquent cast,
 * so it's deliberately not listed here — it's written/read via raw SQL in
 * EmbeddingService instead.
 */
class QuestionEmbedding extends Model
{
    protected $fillable = [
        'quiz_id',
        'question_id',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
