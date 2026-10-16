<?php

namespace App\Services\Import;

use App\Models\Supplier;

interface SupplierDriver
{
    public function __construct(Supplier $supplier);

    public function ids(): iterable;

    public function fetch(string $externalId): ?ProductPayload;
}
