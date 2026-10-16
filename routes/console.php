<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:check')->everyFiveMinutes();

Schedule::command('feed:generate')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();
