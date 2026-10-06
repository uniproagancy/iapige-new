<?php

namespace Tests\Feature;

use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\BlockedByAlta;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Alta used to fail silently, like Elite and Zoommer before it.
 *
 * Every failure returned null — a worker token gone stale, a Cloudflare
 * challenge, a product nobody lists — so a run that imported nothing left
 * nothing behind to say which of those it was. Measured against the live site,
 * alta.ge answers a plain request with a browser challenge: HTTP 403 carrying
 * "Just a moment...", and the worker labels even that application/json, so the
 * content type cannot be used to tell them apart.
 */
class AltaRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.alta.worker_url' => 'https://alta-worker.example.workers.dev',
            'services.alta.token' => 'worker-token',
        ]);
    }

    /**
     * The challenge is the one worth naming: it is not the token, and the fix
     * is a clearance cookie rather than anything in this project's code.
     */
    public function test_a_cloudflare_challenge_names_the_clearance_cookie(): void
    {
        // as the worker really answers: HTML, 403, mislabelled as json
        Http::fake(fn () => Http::response(
            '<html><head><title>Just a moment...</title></head><body>'
            .'<div class="cf-browser-verification"></div></body></html>',
            403,
            ['Content-Type' => 'application/json'],
        ));

        $this->expectException(BlockedByAlta::class);
        $this->expectExceptionMessage('ALTA_CF_CLEARANCE');

        (new AltaClient)->route('100005');
    }

    /** A challenge served under a 200 is still a challenge. */
    public function test_a_challenge_under_a_200_is_still_a_refusal(): void
    {
        Http::fake(fn () => Http::response('<html><title>Just a moment...</title></html>', 200));

        $this->expectException(BlockedByAlta::class);

        (new AltaClient)->route('100005');
    }

    #[DataProvider('refusals')]
    public function test_each_refusal_names_its_own_cause(int $status, string $expected): void
    {
        Http::fake(fn () => Http::response('denied', $status));

        try {
            (new AltaClient)->route('100005');
            $this->fail("HTTP {$status} should have been refused");
        } catch (BlockedByAlta $e) {
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public static function refusals(): array
    {
        return [
            'stale worker token' => [401, 'ALTA_ACCESS_TOKEN'],
            'throttled' => [429, 'IMPORT_RATE_PER_MINUTE'],
            'upstream down' => [503, 'temporary'],
        ];
    }

    /** A product the site does not list is normal, and stays quiet. */
    public function test_an_unlisted_product_stays_quiet(): void
    {
        Http::fake(fn () => Http::response(['products' => []]));

        $this->assertNull((new AltaClient)->route('no-such-code'));
    }

    public function test_a_listed_product_returns_its_route(): void
    {
        Http::fake(fn () => Http::response(['products' => [['route' => '/product/kettle-123']]]));

        $this->assertSame('/product/kettle-123', (new AltaClient)->route('100005'));
    }

    /** The clearance travels with the request, so the worker can forward it. */
    public function test_the_clearance_is_sent_when_it_is_set(): void
    {
        config(['services.alta.cf_clearance' => 'clearance-abc']);

        Http::fake(fn () => Http::response(['products' => []]));

        (new AltaClient)->route('100005');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'cfClearance=clearance-abc'));
    }

    public function test_the_clearance_is_left_out_when_it_is_not_set(): void
    {
        config(['services.alta.cf_clearance' => null]);

        Http::fake(fn () => Http::response(['products' => []]));

        (new AltaClient)->route('100005');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'cfClearance'));
    }
}
