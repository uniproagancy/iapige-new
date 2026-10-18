<?php

namespace App\Console\Commands;

use App\Jobs\SendPixelEventJob;
use App\Services\Facebook\Pixel;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class TestPixel extends Command
{
    protected $signature = 'pixel:test
                            {--event=ViewContent : the event name to send}
                            {--live : send without a test_event_code, as a real conversion}
                            {--queued : go through the queue, the way the site itself does}';

    protected $description = 'Send one server-side event to Meta and report the result';

    public function handle(Pixel $pixel): int
    {

        request()->cookies->set('cookie_consent', 'all');

        if ($this->option('live')) {
            config(['services.facebook.test_event_code' => null]);
        }

        $this->summary($pixel);

        if (! $pixel->enabled()) {
            $this->error('Pixel is not enabled: a pixel id and an access token are both required.');

            return self::FAILURE;
        }

        $captured = null;
        Event::listen(ResponseReceived::class, function (ResponseReceived $e) use (&$captured) {
            $captured = $e->response;
        });

        $event = (string) $this->option('event');
        $eventId = Pixel::eventId('test');

        if ($this->option('queued')) {
            return $this->throughQueue($pixel, $event, $eventId);
        }

        $this->line('');
        $this->line("sending <options=bold>{$event}</> with event_id <options=bold>{$eventId}</> directly, bypassing the queue…");

        $pixel->send($event, [
            'content_type' => 'product',
            'content_ids' => ['pixel-test'],
            'value' => 1.0,
            'currency' => 'GEL',
        ], $eventId);

        if (! $captured) {
            $this->error('No request left this machine — check the pixel log channel.');

            return self::FAILURE;
        }

        return $this->report($captured, $eventId);
    }

    protected function summary(Pixel $pixel): void
    {
        $token = (string) config('services.facebook.access_token');
        $code = config('services.facebook.test_event_code');

        $this->table(['setting', 'value'], [
            ['pixel id', $pixel->pixelId() ?: '— missing —'],
            ['api version', config('services.facebook.api_version')],

            ['access token', $token ? 'set, '.strlen($token).' characters, ends '.substr($token, -6) : '— missing —'],
            ['test_event_code', $code ?: 'none — events count as real conversions'],
            ['app url', config('app.url')],
            ['config cache', app()->configurationIsCached() ? 'ON — run config:clear after editing .env' : 'off'],
            ['queue connection', config('queue.default')],
            ['pixel jobs waiting', $this->waiting()],
            ['failed jobs', $this->failed()],
        ]);
    }

    protected function waiting(): string
    {
        if (config('queue.default') !== 'database') {
            return 'n/a for this queue driver';
        }

        try {
            $all = DB::table('jobs')->count();
            $mine = DB::table('jobs')->where('payload', 'like', '%SendPixelEventJob%')->count();
        } catch (\Throwable $e) {
            return 'could not read the jobs table';
        }

        if ($mine === 0) {
            return $all === 0 ? '0 — the queue is empty' : "0 (but {$all} other jobs are waiting)";
        }

        return "{$mine} waiting — is queue:work running?";
    }

    protected function failed(): string
    {
        try {
            return (string) DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            return 'no failed_jobs table';
        }
    }

    protected function throughQueue(Pixel $pixel, string $event, string $eventId): int
    {
        $payload = $pixel->capture($event, [
            'content_type' => 'product',
            'content_ids' => ['pixel-test'],
            'value' => 1.0,
            'currency' => 'GEL',
        ], $eventId);

        if (! $payload) {
            $this->error('capture() refused: the pixel is disabled, or consent was withheld.');

            return self::FAILURE;
        }

        $before = $this->pending();
        SendPixelEventJob::dispatch($event, $payload);
        $after = $this->pending();

        $this->line('');

        if ($after > $before) {
            $this->warn("queued, and still waiting ({$after} pixel jobs in the table).");
            $this->line('  A worker has to pick it up. Start one with: php artisan queue:work --queue=default');

            return self::FAILURE;
        }

        $this->info('queued and already drained — a worker is running and took it.');
        $this->line("  event_id {$eventId}");

        return self::SUCCESS;
    }

    protected function pending(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        try {
            return DB::table('jobs')->where('payload', 'like', '%SendPixelEventJob%')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function report($response, string $eventId): int
    {
        $body = $response->json() ?? [];

        if ($response->successful() && ($body['events_received'] ?? 0) > 0) {
            $this->info('accepted: events_received='.$body['events_received']);

            if ($trace = $body['fbtrace_id'] ?? null) {
                $this->line('  fbtrace_id '.$trace);
            }

            if ($code = config('services.facebook.test_event_code')) {
                $this->line('');
                $this->line("  Events Manager → Test Events, code <options=bold>{$code}</>, event_id {$eventId}");
            }

            return self::SUCCESS;
        }

        $this->error('refused with HTTP '.$response->status());

        $error = $body['error'] ?? [];

        foreach (['message', 'type', 'code', 'error_subcode', 'fbtrace_id'] as $field) {
            if (isset($error[$field])) {
                $this->line('  '.$field.': '.Redact::secrets((string) $error[$field]));
            }
        }

        if (! $error) {
            $this->line('  '.Redact::secrets($response->body()));
        }

        $this->line('');
        $this->line($this->hint((int) ($error['code'] ?? 0), (int) ($error['error_subcode'] ?? 0)));

        return self::FAILURE;
    }

    protected function hint(int $code, int $subcode): string
    {
        return match (true) {
            $code === 190 => '  190 is an expired or revoked access token — issue a new one in Events Manager.',
            $code === 100 && $subcode === 33 => '  the pixel id is wrong, or this token has no access to that pixel.',
            $code === 100 => '  a field was rejected; the message above names it.',
            $code === 200 => '  the token lacks the ads_management or business_management permission.',
            $code === 2 || $code === 1 => '  a temporary Graph API fault — worth one retry.',
            default => '  see the message above; the full reply is in the pixel log channel.',
        };
    }
}
