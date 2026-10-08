<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:check')->everyFiveMinutes();

/*
 | Facebook rejects an advert whose price or stock no longer matches the shop,
 | so the feed has to follow the imports rather than lead them. Nightly, after
 | the suppliers have been pulled in; the route only ever serves what this
 | last wrote.
 */
Schedule::command('feed:generate')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();
