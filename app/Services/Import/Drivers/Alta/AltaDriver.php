<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Supplier;
use App\Services\Import\ChecksImportSource;
use App\Services\Import\HasStockFeed;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Support\Facades\DB;

class AltaDriver implements ChecksImportSource, HasStockFeed, SupplierDriver
{
    protected AltaB2bClient $b2b;

    protected AltaClient $site;

    protected ?array $rows = null;

    protected bool $checked = false;

    public function __construct(public Supplier $supplier, ?AltaB2bClient $b2b = null, ?AltaClient $site = null)
    {
        $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);
        $this->b2b = $b2b ?? new AltaB2bClient($config);
        $this->site = $site ?? new AltaClient($config);
    }

    public function checkSource(): void
    {
        if ($this->checked) {
            return;
        }

        $wanted = $this->wanted();

        foreach (array_slice($wanted, 0, 5, true) as $code => $row) {
            if ($this->payload((string) $code, $row, $this->site->product((string) $code))) {
                $this->checked = true;

                return;
            }
        }

        if ($wanted !== []) {
            throw new AltaUnavailable('Alta retail details are unavailable for the import sample. Existing products were preserved.');
        }

        $this->checked = true;
    }

    public function ids(): iterable
    {
        $this->checkSource();
        // Refresh the validated stock snapshot before stale empty rows are settled.
        $this->refreshStock();

        return array_map('strval', array_keys($this->wanted()));
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $row = $this->rows[$externalId] ?? $this->b2b->one($externalId);

        if (! $row || (string) ($row['item'] ?? '') !== $externalId) {
            return null;
        }

        return $this->payload($externalId, $row, $this->site->product($externalId));
    }

    public function fromDetails(string $externalId, array $details): ?ProductPayload
    {
        $row = $this->rows[$externalId] ?? $this->b2b->one($externalId);

        if (! $row || (string) ($row['item'] ?? '') !== $externalId) {
            return null;
        }

        return $this->payload($externalId, $row, $details);
    }

    protected function payload(string $code, array $row, ?array $details): ?ProductPayload
    {
        if (! $details || (string) ($details['product']['barCode'] ?? '') !== $code) {
            return null;
        }

        $read = (new AltaJson($this->supplier))->read(json_encode($details, JSON_THROW_ON_ERROR));
        $payload = $read['payload'];

        if (! $payload || ! $payload->images || (! $payload->specs && empty($payload->translations[$this->supplier->config['locale'] ?? 'ka']['description']))) {
            return null;
        }

        if ($this->supplier->markup($payload->costPrice) !== $payload->costPrice
            || ($payload->oldCostPrice !== null && $this->supplier->markup($payload->oldCostPrice) !== $payload->oldCostPrice)) {
            throw new AltaUnavailable('Alta retail prices must be imported unchanged. Remove supplier markup or review unsupported fractional prices first.');
        }

        // Retail price and content come from the exact website product; SOAP supplies quantity only.
        return new ProductPayload(
            externalId: $code,
            sku: $this->supplier->code.'-'.$code,
            costPrice: $payload->costPrice,
            oldCostPrice: $payload->oldCostPrice,
            stock: max(0, (int) ($row['quantity'] ?? 0)),
            brandName: $payload->brandName ?? ($row['brand'] ?? null),
            categoryName: $payload->categoryName ?? ($row['category'] ?? null),
            translations: $payload->translations,
            specs: $payload->specs,
            images: $payload->images,
            weight: $payload->weight ?? ($row['weight'] ?? null),
            variantGroup: $payload->variantGroup,
        );
    }

    protected function wanted(): array
    {
        $minimum = max(1, (int) ($this->supplier->config['min_quantity'] ?? 2));

        return array_filter($this->feed(), fn (array $row) => $row['quantity'] >= $minimum);
    }

    protected function feed(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = $this->b2b->all();

        if (! $rows) {
            throw new AltaUnavailable('Alta B2B returned no rows. The previous stock snapshot was preserved.');
        }

        $indexed = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row['item'] ?? ''));

            if ($code === '' || isset($indexed[$code]) || ! isset($row['quantity']) || ! is_numeric($row['quantity']) || $row['quantity'] < 0) {
                throw new AltaUnavailable('Alta B2B returned an invalid stock snapshot. No stock was changed.');
            }

            $row['quantity'] = (int) $row['quantity'];
            $indexed[$code] = $row;
        }

        return $this->rows = $indexed;
    }

    public function stockRows(): array
    {
        return $this->feed();
    }

    public function refreshStock(): int
    {
        $rows = $this->feed();
        $time = now();

        DB::transaction(function () use ($rows, $time) {
            $batch = [];

            foreach ($rows as $code => $row) {
                $batch[] = [
                    'supplier_id' => $this->supplier->id,
                    'external_id' => (string) $code,
                    'quantity' => $row['quantity'],
                    'cost_price' => is_numeric($row['price'] ?? null) ? $row['price'] : null,
                    'data' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'synced_at' => $time,
                ];
            }

            foreach (array_chunk($batch, 200) as $chunk) {
                DB::table('supplier_stocks')->upsert($chunk, ['supplier_id', 'external_id'], ['quantity', 'cost_price', 'data', 'synced_at']);
            }

            // Only a complete, nonempty, validated response can retire missing items.
            DB::table('supplier_stocks')->where('supplier_id', $this->supplier->id)
                ->whereNotIn('external_id', array_map('strval', array_keys($rows)))
                ->update(['quantity' => 0, 'synced_at' => $time]);
        });

        return count($rows);
    }
}
