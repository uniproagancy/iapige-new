<?php

namespace App\Services\Import\Drivers\Thermocenter;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;

class ThermocenterDriver implements SupplierDriver
{
    protected ThermocenterClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new ThermocenterClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {
        if ($this->supplier->config['scan'] ?? false) {
            $this->scan();
        }

        return SupplierStock::where('supplier_id', $this->supplier->id)
            ->orderBy('external_id')
            ->lazyById(500)
            ->map(fn (SupplierStock $s) => $s->external_id);
    }

    public function scan(): int
    {
        $links = $this->client->links();

        if (! $links) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($links as $url) {
            $rows[] = [
                'supplier_id' => $this->supplier->id,
                'external_id' => $this->codeFor($url),
                'quantity' => 1,
                'data' => json_encode(['url' => $url], JSON_UNESCAPED_UNICODE),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SupplierStock::upsert(
                $chunk,
                ['supplier_id', 'external_id'],
                ['quantity', 'data', 'synced_at', 'updated_at'],
            );
        }

        SupplierStock::where('supplier_id', $this->supplier->id)
            ->whereNotIn('external_id', array_column($rows, 'external_id'))
            ->where('quantity', '>', 0)
            ->update(['quantity' => 0, 'synced_at' => $now]);

        return count($rows);
    }

    protected function codeFor(string $url): string
    {
        $slug = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = rawurldecode($slug);

        return mb_substr($slug, 0, 180) ?: md5($url);
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $row = SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $row) {
            return null;
        }

        $data = $row->data ?? [];
        $url = $data['url'] ?? null;

        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        $page = $this->client->page($url);

        return $page && $page['name'] ? $this->toPayload($externalId, $row, $data, $page) : null;
    }

    protected function toPayload(string $externalId, SupplierStock $row, array $data, array $page): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';

        $cost = (float) $row->cost_price ?: (float) $page['price'];

        if ($cost <= 0) {
            return null;
        }

        $stock = $page['in_stock'] ? max(0, (int) $row->quantity) : 0;

        return new ProductPayload(
            externalId: $externalId,

            sku: $page['sku'] ? $this->supplier->code.'-'.$page['sku'] : $this->supplier->code.'-'.$externalId,
            costPrice: $cost,
            oldCostPrice: $page['old_price'],
            stock: $stock,
            brandName: $page['brand'] ?? null,

            categoryName: $data['category'] ?? $page['category'] ?? null,
            translations: [$locale => [
                'name' => $this->rewrite($page['name']),
                'description' => $this->rewrite($page['description']),
            ]],
            specs: array_map(fn (array $spec) => [
                'name' => $spec['name'],
                'value' => $spec['value'],
                'locale' => $locale,
                'key' => false,
                'filterable' => false,
                'color' => null,
            ], $page['specs'] ?? []),
            images: $page['images'] ?? [],
        );
    }

    protected function rewrite(?string $text): ?string
    {
        if (! $text) {
            return $text;
        }

        foreach ((array) ($this->supplier->config['replace'] ?? []) as $from => $to) {
            $text = preg_replace('/'.preg_quote($from, '/').'/iu', $to, $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
