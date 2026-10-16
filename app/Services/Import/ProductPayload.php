<?php

namespace App\Services\Import;

class ProductPayload
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $sku,
        public readonly float $costPrice,
        public readonly ?float $oldCostPrice,
        public readonly int $stock,
        public readonly ?string $brandName,
        public readonly ?string $categoryName,
        public readonly array $translations,
        public readonly array $specs = [],
        public readonly array $images = [],
        public readonly ?int $weight = null,
        public readonly ?string $variantGroup = null,
        public readonly bool $isPreorder = false,
        public readonly ?string $releaseDate = null,
    ) {}
}
