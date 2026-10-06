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
        $this->expectExceptionMessage('ZOOMMER_CF_CLEARANCE');

        $client->fetch('54500', ['ka']);
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
