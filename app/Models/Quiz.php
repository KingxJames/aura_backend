<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    use HasUuids;

    protected $fillable = [
        'grade_id',
        'title',
        'content_jsonb',
    ];

    // Automatically converts PostgreSQL JSONB format back into a clean PHP Array
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
}