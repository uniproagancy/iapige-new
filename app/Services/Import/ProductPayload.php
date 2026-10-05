<?php

namespace App\Services\Import;

/**
 * What every driver must produce, whatever the source looks like.
 * Translations and specs are keyed by locale, so a single-language feed
 * simply fills one key and the rest fall back at read time.
 */
class ProductPayload
{
    /**
     * @param  array<string, array{name?:string, summary?:string, description?:string}>  $translations
     * @param  array<int, array{name:string, value:string, locale:string, key?:bool}>    $specs
     * @param  array<int, string>                                                        $images
     */
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
    ) {
    }
}
