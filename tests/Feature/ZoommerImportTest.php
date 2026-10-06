<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\Import\Drivers\Zoommer\BlockedByZoommer;
use App\Services\Import\Drivers\Zoommer\ZoommerClient;
use App\Services\Import\Drivers\Zoommer\ZoommerDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zoommer imported nothing, and nothing said why.
 *
 * Two separate faults: a refusal from the source was indistinguishable from a
 * product that does not exist, so a stale cookie read as "no products today";
 * and the price pair was the wrong way round, so a discounted product was
 * bought at its pre-discount price. Both are pinned here.
 */
class ZoommerImportTest extends TestCase
{
    /* ------------------------------------------------------------------ prices */

    public static function priceCases(): array
    {
        return [
            // the real shape: previousPrice is the HIGHER, pre-discount figure
            'discounted' => [['price' => 1399, 'previousPrice' => 1599], 1399.0, 1599.0],
            'not discounted' => [['price' => 579, 'previousPrice' => null], 579.0, null],
            'zero previous' => [['price' => 579, 'previousPrice' => 0], 579.0, null],
            // whichever way round the source sends them, the lower one is cost
            'inverted source' => [['price' => 1599, 'previousPrice' => 1399], 1399.0, 1599.0],
            'equal' => [['price' => 500, 'previousPrice' => 500], 500.0, 500.0],
            'only previous' => [['price' => 0, 'previousPrice' => 800], 800.0, null],
        ];
    }

    #[DataProvider('priceCases')]
    public function test_the_lower_figure_is_what_we_pay(array $raw, float $cost, ?float $old): void
    {
        $payload = $this->payloadFor($raw);

        $this->assertSame($cost, $payload->costPrice);
        $this->assertSame($old, $payload->oldCostPrice);

        // whatever happens, we never buy above the figure we strike through
        if ($payload->oldCostPrice !== null) {
            $this->assertLessThanOrEqual($payload->oldCostPrice, $payload->costPrice);
        }
    }

    /* ------------------------------------------------------------------ blocked vs missing */

    public function test_a_missing_product_is_not_an_error(): void
    {
        $client = $this->clientReturning(new Response(200, [], json_encode([
            'product' => null, 'httpStatusCode' => 400, 'userMessage' => 'Product not found',
        ])));

        $this->assertSame([], $client->fetch('1', ['ka']));
    }

    #[DataProvider('blockedStatuses')]
    public function test_a_refusal_is_loud(int $status): void
    {
        $client = $this->clientReturning(new Response($status, [], 'denied'));

        $this->expectException(BlockedByZoommer::class);
        $this->expectExceptionMessage("HTTP {$status}");

        $client->fetch('54500', ['ka']);
    }

    /**
     * Each refusal names its own cause.
     *
     * 401 and 403 have different answers, and one message covering both sent
     * somebody rotating a cookie that was not the problem. Measured against the
     * live API: the only cookie it checks is zoommer-access_token — dropping
     * cf_clearance still returns 200, dropping the token returns 401 — so a 403
     * is Cloudflare refusing the caller, which no cookie fixes.
     */
    public function test_the_message_says_what_to_fix(): void
    {
        $cases = [
            401 => 'ZOOMMER_ACCESS_TOKEN',
            403 => 'Cloudflare refused the caller',
            429 => 'IMPORT_RATE_PER_MINUTE',
        ];

        foreach ($cases as $status => $expected) {
            try {
                $this->clientReturning(new Response($status, [], 'denied'))->fetch('54500', ['ka']);
                $this->fail("HTTP {$status} should have been refused");
            } catch (BlockedByZoommer $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public static function blockedStatuses(): array
    {
        return [[401], [403], [429], [503]];
    }

    /** Cloudflare sometimes serves its challenge page under a 200. */
    public function test_a_challenge_page_under_a_200_is_a_refusal(): void
    {
        $client = $this->clientReturning(new Response(200, [],
            '<!DOCTYPE html><title>Just a moment...</title><div id="cf-browser-verification">'));

        $this->expectException(BlockedByZoommer::class);

        $client->fetch('54500', ['ka']);
    }

    /* ------------------------------------------------------------------ worker */

    /**
     * With no worker configured the request goes straight to the source.
     *
     * A laptop's address is not refused, so making the worker mandatory would
     * mean nobody could run an import without deploying one.
     */
    public function test_it_calls_the_source_directly_by_default(): void
    {
        config(['services.zoommer.worker_url' => null]);

        $this->assertSame(
            'https://zoommer.ge/api/proxy/v1/Products/details?productId=54500',
            $this->endpointFor('54500'),
        );
    }

    /** The worker's own shape, so the shop and the worker agree. */
    public function test_it_calls_through_the_worker_when_one_is_configured(): void
    {
        config([
            'services.zoommer.worker_url' => 'https://w.example.workers.dev',
            'services.zoommer.access_token' => 'tok-123',
        ]);

        $query = $this->queryOf($this->endpointFor('54500'));

        $this->assertSame('product', $query['type']);
        $this->assertSame('54500', $query['productId']);
        $this->assertSame('tok-123', $query['accessToken']);
    }

    /** The token stays in this project's .env, not duplicated into the worker. */
    public function test_the_token_is_left_out_when_it_is_not_set(): void
    {
        config([
            'services.zoommer.worker_url' => 'https://w.example.workers.dev/',
            'services.zoommer.access_token' => null,
        ]);

        $this->assertArrayNotHasKey('accessToken', $this->queryOf($this->endpointFor('54500')));
    }

    /** Pictures sit on another host, and go the same way when there is a worker. */
    public function test_image_addresses_go_through_the_worker_too(): void
    {
        config([
            'services.zoommer.worker_url' => 'https://w.example.workers.dev',
            'services.zoommer.access_token' => 'tok-123',
        ]);

        $original = 'https://s3.zoommer.ge/site/abc_Thumb.jpeg';
        $query = $this->queryOf((new ZoommerClient)->imageUrl($original));

        $this->assertSame('image', $query['type']);
        $this->assertSame($original, $query['url']);
    }

    public function test_image_addresses_are_untouched_without_a_worker(): void
    {
        config(['services.zoommer.worker_url' => null]);

        $url = 'https://s3.zoommer.ge/site/abc_Thumb.jpeg';

        $this->assertSame($url, (new ZoommerClient)->imageUrl($url));
    }

    protected function endpointFor(string $id): string
    {
        $method = new \ReflectionMethod(ZoommerClient::class, 'endpoint');
        $method->setAccessible(true);

        return $method->invoke(new ZoommerClient, $id);
    }

    /** @return array<string, string> */
    protected function queryOf(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /* ------------------------------------------------------------------ helpers */

    protected function payloadFor(array $priceFields)
    {
        $supplier = new Supplier([
            'code' => 'zoommer',
            'driver' => ZoommerDriver::class,
            'config' => ['locales' => ['ka']],
        ]);

        $driver = new ZoommerDriver($supplier);

        $method = new \ReflectionMethod($driver, 'toPayload');
        $method->setAccessible(true);

        return $method->invoke($driver, '54500', ['ka' => [
            'product' => $priceFields + ['name' => 'Edifier M60', 'isInStock' => false],
        ]]);
    }

    protected function clientReturning(Response $response): ZoommerClient
    {
        $client = new ZoommerClient;

        $guzzle = new Client([
            'handler' => HandlerStack::create(new MockHandler([$response, $response])),
            'http_errors' => false,
        ]);

        $property = new \ReflectionProperty($client, 'client');
        $property->setAccessible(true);
        $property->setValue($client, $guzzle);

        return $client;
    }
}
