<?php

namespace App\Services\Import\Drivers\Citrus;

use App\Models\Supplier;
use App\Models\SupplierStock;

/**
 * Turns a pasted block of addresses into rows the import can run over.
 *
 * One address at a time was the slow part of this supplier: open the product,
 * copy the address, fill a form, submit, wait, repeat. A page of forty
 * products is forty of those. Pasting the lot and walking away is the whole
 * point of this class.
 *
 * Nothing is fetched here. The addresses are checked for shape, reduced to
 * their slugs and written down; the import then runs over them like any other
 * supplier's rows, through the same queue, with the same retries. Which also
 * means a bad address costs one row rather than a failed form.
 */
class CitrusLinks
{
    public function __construct(protected CitrusClient $client) {}

    /**
     * @return array{added:int, updated:int, skipped:array<int, string>}
     */
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

            // the same product pasted twice is one row, not two
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
                    /*
                     * The quantity is a placeholder. Citrus reports its own
                     * stock on every lookup, so this row only has to say that
                     * the product is wanted — a zero here would have the
                     * manager skip it before it was ever asked about.
                     */
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

    /**
     * The addresses out of whatever was pasted.
     *
     * People paste from a browser, a spreadsheet column or a chat message, so
     * the separator is a newline, a comma or a space — and a line may carry
     * the product's name in front of its address.
     *
     * @return array<int, string>
     */
    protected function lines(string $pasted): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($pasted), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn (string $part) => mb_strlen($part) > 3));
    }

    /**
     * Whether this is one of theirs.
     *
     * A slug pasted on its own is accepted — somebody copying from a list may
     * well have only that — but an address belonging to another shop is not,
     * because the API would answer for a slug that happens to collide and the
     * wrong product would arrive wearing the right name.
     */
    protected function looksLikeCitrus(string $line): bool
    {
        if (! str_contains($line, '/')) {
            return true;
        }

        $host = parse_url($line, PHP_URL_HOST);

        return $host === null || str_contains($host, 'citrus.ge');
    }
}
