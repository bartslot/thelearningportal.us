<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('elevenlabs:warm')->cron('*/50 * * * *');

// Drain the queue without a dedicated daemon. SiteGround shared hosting has no long-running
// worker and no SSH crontab, but `schedule:run` already fires every minute (it runs the warm
// command above), so process the queue here each minute. --stop-when-empty exits when idle;
// withoutOverlapping prevents two workers stacking; runInBackground keeps schedule:run snappy.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=2')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
