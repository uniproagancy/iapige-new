<?php

namespace App\Services\Import;

use App\Models\Supplier;

/**
 * A driver knows one source and nothing else: how to reach it, how to walk it
 * and how to turn its JSON into a ProductPayload. Prices, categories, slugs,
 * images and the database are none of its business.
 */
interface SupplierDriver
{
    public function __construct(Supplier $supplier);

    /** External ids to import; lazy, so a large feed never fills memory. */
    public function ids(): iterable;

    /** One product in every language the source offers, or null when it is gone. */
    public function fetch(string $externalId): ?ProductPayload;
}
