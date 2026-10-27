<?php

declare(strict_types=1);

namespace AltaTests;

use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

final class AltaClientTest extends TestCase
{
    public function test_fuzzy_first_result_cannot_replace_the_exact_barcode_product(): void
    {
        Http::fake(function (Request $request) {
            self::assertSame(['ka'], $request->header('Accept-Language'));
            if (str_contains($request->url(), '/search/suggestions')) {
                return Http::response(['products' => [
                    ['id' => 740, 'barCode' => '001235', 'name' => 'Wrong first result'],
                    ['id' => 741, 'barCode' => '001234', 'name' => 'Correct exact result'],
                ]]);
            }
            if (str_contains($request->url(), '/Products/details')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(($query['productId'] ?? null) === '741'
                    ? $this->details()
                    : $this->details(['id' => 740, 'barCode' => '001235', 'name' => 'Wrong first result', 'price' => 99]));
            }

            throw new \LogicException('Unexpected fixture endpoint: '.$request->url());
        });

        $details = (new AltaClient(['token' => 'synthetic-token']))->product('001234');
        self::assertNotNull($details);
        self::assertSame('001234', $details['product']['barCode']);
        self::assertSame(741, $details['product']['id']);
        self::assertSame(799.0, (float) $details['product']['price']);
        self::assertSame('<p>Synthetic exact-product description.</p>', $details['product']['description']);
        Http::assertSentCount(2);
    }

    public function test_candidate_id_is_not_trusted_when_details_barcode_disagrees(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), '/search/suggestions')
                ? Http::response(['products' => [['id' => 741, 'barCode' => '001234']]])
                : Http::response($this->details(['barCode' => '1234']));
        });

        self::assertNull((new AltaClient)->product('001234'), 'Leading zeroes are part of the supplier identity.');
    }

    public function test_genuine_empty_search_is_distinct_from_an_unavailable_api(): void
    {
        Http::fake(['*' => Http::response(['products' => []])]);

        self::assertNull((new AltaClient)->product('001234'));
    }

    public function test_unidentified_candidates_are_bounded_and_never_trusted_without_an_exact_details_barcode(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/search/suggestions')) {
                return Http::response(['products' => array_map(fn ($id) => ['id' => $id], range(741, 760))]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response($this->details(['id' => (int) $query['productId'], 'barCode' => 'different']));
        });

        self::assertNull((new AltaClient)->product('001234'));
        self::assertCount(5, Http::recorded(fn (Request $request) => str_contains($request->url(), '/Products/details')));
    }

    #[DataProvider('unavailableResponses')]
    public function test_unavailable_or_invalid_response_throws_instead_of_becoming_an_empty_catalogue(int $status, string $body, string $contentType): void
    {
        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => $contentType])]);
        $this->expectException(AltaUnavailable::class);

        (new AltaClient)->product('001234');
    }

    public static function unavailableResponses(): array
    {
        return [
            'blocked' => [403, '{"error":"denied"}', 'application/json'],
            'challenge with HTTP 200' => [200, '<html><title>Just a moment...</title></html>', 'text/html'],
            'invalid JSON with HTTP 200' => [200, '{not-valid-json', 'application/json'],
            'upstream failure' => [500, '{"error":"temporarily unavailable"}', 'application/json'],
        ];
    }
}
