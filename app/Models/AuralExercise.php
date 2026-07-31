<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuralExercise extends Model
{
    protected $fillable = [
        'user_id',
        'grade_id',
        'module_type',
        'payload_jsonb',
        'is_baseline',
    ];

    protected $casts = [
        // Lets us read/write this column as a plain PHP array instead of a raw JSON string.
        'payload_jsonb' => 'array',
        'is_baseline' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AuralModuleAttempt::class);
    }
}
