<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{

    protected $fillable = [
        'level_number',
        'syllabus_focus',
    ];

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }
}