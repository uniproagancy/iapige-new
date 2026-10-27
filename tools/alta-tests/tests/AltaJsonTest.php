<?php

declare(strict_types=1);

namespace AltaTests;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaJson;

final class AltaJsonTest extends TestCase
{
    public function test_current_retail_price_and_genuine_higher_previous_price_are_preserved(): void
    {
        $read = (new AltaJson(new Supplier(['code' => 'alta', 'config' => ['locale' => 'ka']])))->read(
            json_encode($this->details(), JSON_THROW_ON_ERROR)
        );
        self::assertSame([], $read['errors']);
        $payload = $read['payload'];
        self::assertNotNull($payload);
        self::assertSame('001234', $payload->externalId);
        self::assertSame('alta-001234', $payload->sku);
        self::assertSame(799.0, $payload->costPrice);
        self::assertSame(999.0, $payload->oldCostPrice);
        self::assertSame('<p>Synthetic exact-product description.</p>', $payload->translations['ka']['description']);
        self::assertSame(['https://images.invalid/primary.webp', 'https://images.invalid/side.webp'], $payload->images);
        self::assertSame('16 GB', $payload->specs[0]['value']);
    }

    public function test_equal_or_lower_previous_price_is_never_displayed_as_a_discount(): void
    {
        foreach ([799, 599, 0, null] as $previous) {
            $read = (new AltaJson(new Supplier(['code' => 'alta'])))->read(
                json_encode($this->details(['previousPrice' => $previous]), JSON_THROW_ON_ERROR)
            );
            self::assertNotNull($read['payload']);
            self::assertSame(799.0, $read['payload']->costPrice);
            self::assertNull($read['payload']->oldCostPrice);
        }
    }

    public function test_missing_or_nonpositive_retail_price_is_rejected_instead_of_promoting_previous_price(): void
    {
        foreach ([0, -1, null, ''] as $price) {
            $read = (new AltaJson(new Supplier(['code' => 'alta'])))->read(
                json_encode($this->details(['price' => $price, 'previousPrice' => 999]), JSON_THROW_ON_ERROR)
            );
            self::assertNull($read['payload']);
            self::assertNotEmpty($read['errors']);
        }
    }
}
