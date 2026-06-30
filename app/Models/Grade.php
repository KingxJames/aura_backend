<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    protected $fillable = [
        'title',
        'level_number',
        'description',
        'syllabus_focus',
    ];

    protected $casts = [
        'level_number' => 'integer', // Ensures strict type matching works flawlessly
    ];

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }
}
