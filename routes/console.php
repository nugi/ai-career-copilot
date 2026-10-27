<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('exchange-rates:fetch')->dailyAt('05:30')->onOneServer();
Schedule::command('jobs:check-source')->dailyAt('06:00');
Schedule::command('jobs:ingest')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
