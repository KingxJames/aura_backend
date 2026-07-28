<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\Expo\ExpoPushToken;

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
        'provider',
        'elo_rating',
        'warm_up_streak',
        'warm_up_longest_streak',
        'last_warm_up_date',
        'study_arm',
        'study_enrolled_at',
    ];

    protected $casts = [
        'elo_rating' => 'float',
        'last_warm_up_date' => 'date',
        'study_enrolled_at' => 'datetime',
    ];

    /**
     * Record a completed Tuning Fork warm-up for today and update the daily streak.
     * Locks the row for the duration of the read-modify-write to avoid a double-submit race.
     */
    public function recordWarmUp(): array
    {
        return DB::transaction(function () {
            $user = self::query()->lockForUpdate()->findOrFail($this->id);

            $today = Carbon::today();
            $lastDate = $user->last_warm_up_date;
            $isNewRecord = false;

            if ($lastDate === null || !$lastDate->isToday()) {
                $user->warm_up_streak = $lastDate?->isYesterday()
                    ? $user->warm_up_streak + 1
                    : 1;

                $user->last_warm_up_date = $today;

                if ($user->warm_up_streak > $user->warm_up_longest_streak) {
                    $user->warm_up_longest_streak = $user->warm_up_streak;
                    $isNewRecord = true;
                }

                $user->save();
                $this->warm_up_streak = $user->warm_up_streak;
                $this->warm_up_longest_streak = $user->warm_up_longest_streak;
                $this->last_warm_up_date = $user->last_warm_up_date;
            }

            return [
                'current' => $user->warm_up_streak,
                'longest' => $user->warm_up_longest_streak,
                'is_new_record' => $isNewRecord,
            ];
        });
    }

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

    public function quizSessions()
    {
        return $this->hasMany(QuizSessions::class);
    }

    public function sessions()
    {
        return $this->hasMany(QuizSessions::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * @return Collection<int, ExpoPushToken>
     */
    public function routeNotificationForExpo(): Collection
    {
        return $this->pushTokens->map(fn (PushToken $pushToken) => ExpoPushToken::make($pushToken->token));
    }
}
