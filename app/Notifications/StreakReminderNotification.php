<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class StreakReminderNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['expo'];
    }

    public function toExpo(User $notifiable): ExpoMessage
    {
        return ExpoMessage::create()
            ->title("Don't lose your streak!")
            ->body("You're on a {$notifiable->warm_up_streak}-day streak. Do today's Tuning Fork warm-up to keep it alive.");
    }
}
