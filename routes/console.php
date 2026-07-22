<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed UTC time for now — no per-user timezone support yet.
// Also requires a crontab entry running `php artisan schedule:run` every
// minute in prod, or this will never fire regardless of the code.
Schedule::command('reminders:streak')->dailyAt('18:00');
