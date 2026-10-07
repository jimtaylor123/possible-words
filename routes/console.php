<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('words:generate', ['--count' => 20])->daily();
Schedule::command('words:release')->everySixHours()->withoutOverlapping();
Schedule::command('words:check-domains --chunk=20 --limit=20')->everyFifteenMinutes()->withoutOverlapping();
