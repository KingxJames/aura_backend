<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'username',
        'current_grade_level',
        'profile_picture',
    ];

    public function progress(): HasMany
    {
        return $this->hasMany(UserProgress::class);
    }

    public function auralAttempts(): HasMany
    {
        return $this->hasMany(AuralAttempt::class);
    }

    public function tutorConversations(): HasMany
    {
        return $this->hasMany(TutorConversation::class);
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }
}