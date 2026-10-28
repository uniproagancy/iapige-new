<?php

use App\Services\Import\JsonImportSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:check')->everyFiveMinutes();

Schedule::command('alta:stock')
    ->twiceDaily(9, 21)
    ->timezone('Asia/Tbilisi')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('queue:work --queue=import:alta --stop-when-empty --max-time=50 --timeout=300 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('feed:generate')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::call(fn () => app(JsonImportSource::class)->purgeExpired(100))
    ->name('json-imports:cleanup')
    ->hourly()
    ->withoutOverlapping(10);
