<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'grade_id',
        'title',
        'content_jsonb',
    ];

    protected $casts = [
        'content_jsonb' => 'array',
    ];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(UserProgress::class);
    }

    /**
     * Helper to instantly fetch the questions array as a Laravel Collection
     */
    public function getQuestionsCollection()
    {
        return collect($this->content_jsonb ?? []);
    }
}
