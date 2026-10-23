<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('words:generate', ['--count' => 20])->daily();
Schedule::command('words:release')->everySixHours()->withoutOverlapping();
Schedule::command('words:check-domains --chunk=8 --limit=8')->cron('*/11 * * * *');
Schedule::command('notifications:send-digests')->dailyAt('08:00')->timezone('UTC')->withoutOverlapping();
Schedule::command('notifications:retry-immediate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('notifications:prune')->dailyAt('08:30')->timezone('UTC')->withoutOverlapping();
Schedule::command('backup:run')->everySixHours()->withoutOverlapping();
Schedule::command('backup:check-freshness')->hourlyAt(20)->withoutOverlapping();
