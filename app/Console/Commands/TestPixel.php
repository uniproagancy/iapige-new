<?php

namespace App\Console\Commands;

use App\Services\Facebook\Pixel;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;

/**
 * Fires one real event at Meta and prints what came back.
 *
 * The unit tests fake the HTTP layer, so they prove the payload is built
 * correctly but never that Meta accepts it — a wrong pixel id, a dead token
 * or a rejected field all pass those tests and still send nothing. This
 * command closes that gap: it drives the same Pixel service the shop uses and
 * shows the Graph API's own answer.
 */
class TestPixel extends Command
{
    protected $signature = 'pixel:test
                            {--event=ViewContent : the event name to send}
                            {--live : send without a test_event_code, as a real conversion}';

    protected $description = 'Send one server-side event to Meta and report the result';

    public function handle(Pixel $pixel): int
    {
        /*
         | Consent lives in a cookie the browser sets, and there is no browser
         | here. Setting it on the console request is what lets the real
         | send() path run instead of a special one written just for testing.
         */
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

        $this->line('');
        $this->line("sending <options=bold>{$event}</> with event_id <options=bold>{$eventId}</>…");

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
            // never the token itself: this output gets pasted into tickets
            ['access token', $token ? 'set, '.strlen($token).' characters' : '— missing —'],
            ['test_event_code', $code ?: 'none — events count as real conversions'],
        ]);
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

    /** Meta's codes say little on their own. */
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
