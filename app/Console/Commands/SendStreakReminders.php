<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\StreakReminderNotification;
use Illuminate\Console\Command;

class SendStreakReminders extends Command
{
    protected $signature = 'reminders:streak';

    protected $description = "Notify users whose warm-up streak will be lost if they don't practice today.";

    public function handle(): int
    {
        $users = User::where('warm_up_streak', '>', 0)
            ->whereDate('last_warm_up_date', today()->subDay())
            ->whereHas('pushTokens')
            ->get();

        foreach ($users as $user) {
            $user->notify(new StreakReminderNotification());
        }

        $this->info("Sent streak reminders to {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
