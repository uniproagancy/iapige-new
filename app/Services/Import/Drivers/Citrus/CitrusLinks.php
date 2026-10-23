<?php

namespace App\Services\Import\Drivers\Citrus;

use App\Models\Supplier;
use App\Models\SupplierStock;

class CitrusLinks
{
    public function __construct(protected CitrusClient $client) {}

    public function add(Supplier $supplier, string $pasted, ?string $category = null): array
    {
        $added = 0;
        $updated = 0;
        $skipped = [];
        $seen = [];

        foreach ($this->lines($pasted) as $line) {
            $slug = $this->client->slug($line);

            if (! $slug || ! $this->looksLikeCitrus($line)) {
                $skipped[] = $line;

                continue;
            }

            if (isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $existing = SupplierStock::where('supplier_id', $supplier->id)
                ->where('external_id', $slug)
                ->first();

            SupplierStock::updateOrCreate(
                ['supplier_id' => $supplier->id, 'external_id' => $slug],
                [

                    'quantity' => 1,
                    'cost_price' => null,
                    'data' => array_filter([
                        'slug' => $slug,
                        'url' => str_starts_with($line, 'http') ? $line : null,
                        'category' => $category,
                    ]),
                    'synced_at' => now(),
                ],
            );

            $existing ? $updated++ : $added++;
        }

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    protected function lines(string $pasted): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($pasted), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn (string $part) => mb_strlen($part) > 3));
    }

    protected function looksLikeCitrus(string $line): bool
    {
        if (! str_contains($line, '/')) {
            return true;
        }

        $host = parse_url($line, PHP_URL_HOST);

        if ($host !== null && ! str_contains($host, 'citrus.ge')) {
            return false;
        }

        return $this->looksLikeProduct($line);
    }

    /* A category address such as /products/179 also ends in something that
       passes for a slug, and it would sit in the queue failing forever. Only
       a product address is taken. */
    protected function looksLikeProduct(string $line): bool
    {
        $path = parse_url($line, PHP_URL_PATH) ?: $line;
        $parts = array_values(array_filter(explode('/', $path)));

        return in_array('product', $parts, true);
    }
}
