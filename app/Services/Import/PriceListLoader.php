<?php

namespace App\Services\Import;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Support\XlsxReader;
use RuntimeException;

class PriceListLoader
{
    public const MODE_REPLACE = 'replace';

    public const MODE_MERGE = 'merge';

    public const MODES = [self::MODE_REPLACE, self::MODE_MERGE];

    public function load(
        Supplier $supplier,
        string $path,
        int $sheet = 1,
        int $skipRows = 1,
        string $mode = self::MODE_REPLACE,
    ): array {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Unknown load mode [{$mode}].");
        }

        $map = $supplier->config['columns'] ?? null;

        if (! $map || empty($map['key'])) {
            throw new RuntimeException("No column map on supplier [{$supplier->code}]. Add config.columns.");
        }

        $now = now();
        $rows = [];
        $read = 0;

        foreach ($this->read($path, $sheet) as $i => $cells) {
            if ($i < $skipRows) {
                continue;
            }

            $key = trim((string) ($cells[$map['key']] ?? ''));

            if ($key === '') {
                continue;
            }

            $rows[] = [
                'supplier_id' => $supplier->id,
                'external_id' => $key,
                'quantity' => $this->quantity($cells, $map),
                'cost_price' => $this->money($cells[$map['cost'] ?? ''] ?? null),

                'data' => json_encode($this->pick($cells, $map), JSON_UNESCAPED_UNICODE),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $read++;
        }

        if (! $rows) {
            return [
                'read' => 0,
                'zeroed' => 0,
                'missing_url' => 0,
                'no_stock_column' => empty($map['stock']),
                'mode' => $mode,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SupplierStock::upsert(
                $chunk,
                ['supplier_id', 'external_id'],
                ['quantity', 'cost_price', 'data', 'synced_at', 'updated_at'],
            );
        }

        $zeroed = $mode === self::MODE_REPLACE
            ? SupplierStock::where('supplier_id', $supplier->id)
                ->whereNotIn('external_id', array_column($rows, 'external_id'))
                ->where('quantity', '>', 0)
                ->update(['quantity' => 0, 'synced_at' => $now])
            : 0;

        return [
            'read' => $read,
            'zeroed' => $zeroed,
            'missing_url' => $this->withoutLink($supplier, $map, $now),

            'no_stock_column' => empty($map['stock']),
            'mode' => $mode,
        ];
    }

    protected function withoutLink(Supplier $supplier, array $map, $now): int
    {
        if (empty($map['url'])) {
            return 0;
        }

        return SupplierStock::where('supplier_id', $supplier->id)
            ->where('synced_at', $now)
            ->whereRaw('json_extract(data, "$.url") not like "http%"')
            ->count();
    }

    protected function read(string $path, int $sheet): iterable
    {
        if (str_ends_with(strtolower($path), '.csv')) {
            return $this->csv($path);
        }

        return (new XlsxReader($path))->rows($sheet);
    }

    protected function csv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $separator = $this->guessSeparator($path);

        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            $cells = [];

            foreach (array_values($data) as $i => $value) {
                $cells[$this->columnName($i)] = trim((string) $value);
            }

            yield $cells;
        }

        fclose($handle);
    }

    protected function columnName(int $index): string
    {
        $name = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26).$name;
        }

        return $name;
    }

    protected function guessSeparator(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'));

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    protected function pick(array $cells, array $map): array
    {
        $picked = [];
        $links = $cells['_links'] ?? [];

        foreach ($map as $role => $column) {
            if ($role === 'key' || ! is_string($column)) {
                continue;
            }

            $value = $role === 'url'
                ? trim((string) ($links[$column] ?? $cells[$column] ?? ''))
                : trim((string) ($cells[$column] ?? ''));

            if ($value !== '') {
                $picked[$role] = $value;
            }
        }

        return $picked;
    }

    protected function quantity(array $cells, array $map): int
    {
        if (empty($map['stock'])) {
            return 1;
        }

        $text = trim((string) ($cells[$map['stock']] ?? ''));

        if ($text === '') {
            return 1;
        }

        if (is_numeric($text)) {
            return max(0, (int) floor((float) $text));
        }

        $digits = preg_replace('/\D+/', '', $text);

        return $digits === '' ? 0 : min(PHP_INT_MAX, (int) $digits);
    }

    protected function money(?string $text): ?float
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            return (float) $text;
        }

        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', $text));

        return $digits === '' ? null : (float) $digits;
    }
}
