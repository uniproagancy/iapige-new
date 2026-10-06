<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Support\XlsxReader;
use Illuminate\Console\Command;

/**
 * Loads a supplier's price list into supplier_stocks.
 *
 * The column map lives on the supplier, because every supplier writes a
 * different spreadsheet and none of them will change it for us. The file is
 * treated as the whole truth: a code missing from it drops to zero, so a
 * product that ran out simply stops being available.
 */
class ImportSupplierFile extends Command
{
    protected $signature = 'import:file
                            {supplier : the supplier code}
                            {file : path to .xlsx or .csv}
                            {--sheet=1}
                            {--skip-rows=1 : header rows to ignore}';

    protected $description = 'Import a supplier price list (xlsx or csv)';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->firstOrFail();
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $map = $supplier->config['columns'] ?? null;

        if (! $map || empty($map['key'])) {
            $this->error("No column map on supplier [{$supplier->code}]. Add config.columns.");

            return self::FAILURE;
        }

        $now = now();
        $rows = [];
        $read = 0;
        $skip = (int) $this->option('skip-rows');

        foreach ($this->read($path) as $i => $cells) {
            if ($i < $skip) {
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
                // the rest of the row travels with it; the driver decides what it means
                'data' => json_encode($this->pick($cells, $map), JSON_UNESCAPED_UNICODE),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $read++;
        }

        if (! $rows) {
            $this->warn('The file contained no usable rows.');

            return self::SUCCESS;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SupplierStock::upsert(
                $chunk,
                ['supplier_id', 'external_id'],
                ['quantity', 'cost_price', 'data', 'synced_at', 'updated_at'],
            );
        }

        // whatever the new file does not mention is out of stock
        $zeroed = SupplierStock::where('supplier_id', $supplier->id)
            ->where('synced_at', '<', $now)
            ->update(['quantity' => 0, 'synced_at' => $now]);

        if (! empty($map['url'])) {
            $missing = SupplierStock::where('supplier_id', $supplier->id)
                ->where('synced_at', $now)
                ->whereRaw('json_extract(data, "$.url") not like "http%"')
                ->count();

            if ($missing) {
                $this->warn("{$missing} rows carry no link and will be skipped.");
            }
        }

        $this->info("{$supplier->code}: {$read} rows loaded, {$zeroed} zeroed out.");
        $this->line("Next: php artisan import:run {$supplier->code}");

        return self::SUCCESS;
    }

    /** @return iterable<int, array<string, string>> */
    protected function read(string $path): iterable
    {
        if (str_ends_with(strtolower($path), '.csv')) {
            return $this->csv($path);
        }

        return (new XlsxReader($path))->rows((int) $this->option('sheet'));
    }

    /** CSV columns are named A, B, C… too, so the map stays the same either way. */
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

    /**
     * Everything the map names, keyed by its role rather than its column.
     *
     * A "url" role reads the cell's hyperlink rather than its text, because
     * some suppliers put the product's address behind the model's own name
     * instead of giving it a column.
     */
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

    /**
     * Stock reads as "30+", "100+", "0" or a plain number.
     *
     * A price list with no quantity column at all means the supplier does not
     * report stock, not that everything is out of it — so a row from such a
     * file counts as one available rather than none.
     */
    protected function quantity(array $cells, array $map): int
    {
        if (empty($map['stock'])) {
            return 1;
        }

        $text = trim((string) ($cells[$map['stock']] ?? ''));

        if ($text === '') {
            return 1;
        }

        /*
         * Read as a number first, because a spreadsheet writes small fractions
         * in scientific notation: Excel stores 0.04 as "4.0000000000000001E-2".
         * Stripping the non-digits out of that left "40000000000000001-2",
         * which became forty quadrillion — out of range for the column, so the
         * insert failed and took its whole batch of five hundred rows with it.
         * One such cell stopped a nine thousand row price list at one thousand.
         */
        if (is_numeric($text)) {
            return max(0, (int) floor((float) $text));
        }

        // free text: "30+", ">=10", "12 ცალი"
        $digits = preg_replace('/\D+/', '', $text);

        return $digits === '' ? 0 : min(PHP_INT_MAX, (int) $digits);
    }

    protected function money(?string $text): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $text));

        return $digits === '' ? null : (float) $digits;
    }
}
