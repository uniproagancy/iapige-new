<?php

namespace App\Services\SalesChat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesChatVisitorHistory
{
    private const TIMEZONE = 'Asia/Tbilisi';

    public function initialBrowserToken(string $sessionId): string
    {
        return $this->keyedHash('iapi.visitors.browser.v1:', $sessionId);
    }

    public function record(string $browserToken): void
    {
        DB::table('sales_chat_visitor_days')->insertOrIgnore([
            'visited_on' => now(self::TIMEZONE)->toDateString(),
            'browser_hash' => $this->keyedHash('iapi.visitors.history.v1:', $browserToken),
        ]);
    }

    public function summary(array $query): array
    {
        $now = now(self::TIMEZONE);
        $today = $now->toDateString();
        $period = is_string($query['period'] ?? 'today') ? $query['period'] ?? 'today' : '';
        $from = $today;
        $to = $today;
        $error = null;

        switch ($period) {
            case 'today':
                break;
            case 'week':
                $from = $now->startOfWeek(Carbon::MONDAY)->toDateString();
                break;
            case 'month':
                $from = $now->startOfMonth()->toDateString();
                break;
            case 'custom':
                $start = $this->validDate($query['from'] ?? null);
                $end = $this->validDate($query['to'] ?? null);
                $from = $start ?? $today;
                $to = $end ?? $today;
                if ($start === null || $end === null || $from > $to || $to > $today) {
                    $error = 'invalid_dates';
                }
                break;
            default:
                $period = 'today';
                $error = 'invalid_period';
        }

        $startedAt = DB::table('sales_chat_visitor_history')->where('id', 1)->value('started_at_utc');
        $trackingStart = $startedAt === null ? null : Carbon::parse($startedAt, 'UTC')->setTimezone(self::TIMEZONE);
        $trackedFrom = $trackingStart?->toDateString();
        $overlapsTracking = $error === null && $trackedFrom !== null && $to >= $trackedFrom;

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'uniqueCount' => $overlapsTracking
                ? DB::table('sales_chat_visitor_days')->whereBetween('visited_on', [max($from, $trackedFrom), $to])
                    ->distinct()->count('browser_hash')
                : null,
            'trackedFrom' => $trackedFrom,
            'complete' => $overlapsTracking && $trackingStart->lte(Carbon::parse($from, self::TIMEZONE)),
            'today' => $today,
            'error' => $error,
        ];
    }

    private function validDate(mixed $date): ?string
    {
        if (! is_string($date) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $date;
    }

    private function keyedHash(string $prefix, string $value): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503);

        return hash_hmac('sha256', $prefix.$value, $key);
    }
}
