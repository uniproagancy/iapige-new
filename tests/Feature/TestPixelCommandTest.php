<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The smoke test for the smoke test.
 *
 * PixelTest proves the payload is built correctly against a faked transport,
 * which a wrong pixel id or a dead token both survive. pixel:test exists to
 * catch exactly those, so what matters here is that it reports Meta's answer
 * faithfully — and never prints the access token while doing it.
 */
class TestPixelCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.pixel_id' => '123456',
            'services.facebook.access_token' => 'secret-token-value',
            'services.facebook.api_version' => 'v24.0',
            'services.facebook.test_event_code' => 'TEST91910',
        ]);
    }

    public function test_an_accepted_event_is_reported(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'Abc123']),
        ]);

        $this->artisan('pixel:test')
            ->expectsOutputToContain('events_received=1')
            ->expectsOutputToContain('Abc123')
            ->assertSuccessful();
    }

    /** A dead token is the failure this command exists to find. */
    public function test_an_expired_token_is_explained(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Error validating access token', 'code' => 190, 'type' => 'OAuthException'],
            ], 400),
        ]);

        $this->artisan('pixel:test')
            ->expectsOutputToContain('refused with HTTP 400')
            ->expectsOutputToContain('expired or revoked access token')
            ->assertFailed();
    }

    /** A wrong pixel id reads as a permission error unless it is spelled out. */
    public function test_a_wrong_pixel_id_is_explained(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Unsupported post request', 'code' => 100, 'error_subcode' => 33],
            ], 400),
        ]);

        $this->artisan('pixel:test')
            ->expectsOutputToContain('pixel id is wrong')
            ->assertFailed();
    }

    /**
     * The token reaches Meta and nothing else.
     *
     * This output gets pasted into tickets and chat windows, which is how a
     * token ends up somewhere it cannot be taken back from.
     */
    public function test_the_access_token_is_never_printed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
        ]);

        $this->artisan('pixel:test')
            ->doesntExpectOutputToContain('secret-token-value')
            ->expectsOutputToContain('set, 18 characters')
            ->assertSuccessful();
    }

    /** --live is the only way to send something Meta will count. */
    public function test_live_drops_the_test_event_code(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
        ]);

        $this->artisan('pixel:test', ['--live' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => ! isset($request->data()['test_event_code']));
    }

    /** And without it, the test code rides along. */
    public function test_the_test_event_code_is_sent_by_default(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
        ]);

        $this->artisan('pixel:test')->assertSuccessful();

        Http::assertSent(fn ($request) => ($request->data()['test_event_code'] ?? null) === 'TEST91910');
    }

    /** Nothing configured is a failure, not a silent success. */
    public function test_a_missing_token_stops_the_command(): void
    {
        config(['services.facebook.access_token' => null]);

        $this->artisan('pixel:test')
            ->expectsOutputToContain('not enabled')
            ->assertFailed();
    }
}
