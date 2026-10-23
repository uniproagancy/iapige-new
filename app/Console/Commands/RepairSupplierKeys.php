<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 | A price list once read the hyperlink behind a cell instead of the code in
 | it, so part of a supplier's catalogue came in named after a web address.
 | This pairs each of those with the row that carries the same address under
 | its proper code, and hands the product over to it.
 |
 | Nothing is deleted unless it is a duplicate with nothing of its own.
 */
class RepairSupplierKeys extends Command
{
    protected $signature = 'import:repair-keys
        {supplier : the supplier code}
        {--dry : show what would be done and stop}';

    protected $description = 'Return rows keyed by a web address to their proper code';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        $rows = SupplierStock::where('supplier_id', $supplier->id)
            ->get(['id', 'external_id', 'data']);

        $byUrl = [];

        foreach ($rows as $row) {
            $url = $this->address($row);

            if ($url && ! $this->looksLikeUrl($row->external_id)) {
                $byUrl[$url] = $row->external_id;
            }
        }

        $wrong = $rows->filter(fn (SupplierStock $row) => $this->looksLikeUrl($row->external_id));

        if ($wrong->isEmpty()) {
            $this->info('Every row is keyed by a code already.');

            return self::SUCCESS;
        }

        $paired = [];
        $alone = [];

        foreach ($wrong as $row) {
            $code = $byUrl[$this->address($row)] ?? null;

            $code ? $paired[$row->external_id] = $code : $alone[] = $row->external_id;
        }

        $this->table(['', 'rows'], [
            ['keyed by an address', $wrong->count()],
            ['paired with their code', count($paired)],
            ['with no code to return to', count($alone)],
        ]);

        if ($paired) {
            $this->line('');
            $this->line('  the first few pairings:');

            foreach (array_slice($paired, 0, 6, true) as $was => $now) {
                $this->line('    '.mb_substr($was, 0, 52).'  →  '.$now);
            }
        }

        if ($this->option('dry')) {
            $this->newLine();
            $this->info('Nothing was changed. Run it without --dry to apply.');

            return self::SUCCESS;
        }

        $moved = $this->apply($supplier, $paired);

        $this->newLine();
        $this->line('  offers handed over:   '.$moved['offers']);
        $this->line('  products renamed:     '.$moved['renamed']);
        $this->line('  duplicates retired:   '.$moved['retired']);
        $this->line('  rows removed:         '.$moved['rows']);

        if ($alone) {
            $this->newLine();
            $this->warn(count($alone).' rows have no proper code to return to and were left alone.');
            $this->line('  Upload the price list again so their codes are read, then run this once more.');
        }

        return self::SUCCESS;
    }

    protected function apply(Supplier $supplier, array $paired): array
    {
        $offers = $renamed = $retired = $rows = 0;

        foreach ($paired as $was => $now) {
            DB::transaction(function () use ($supplier, $was, $now, &$offers, &$renamed, &$retired, &$rows) {
                $stray = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $was)->first();
                $proper = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $now)->first();

                if ($stray && ! $proper) {
                    /* Nothing came in under the code, so the product it made
                       simply takes the right name. */
                    $stray->update(['external_id' => $now]);
                    $offers++;

                    if ($product = Product::find($stray->product_id)) {
                        $product->forceFill(['sku' => $supplier->code.'-'.$now])->save();
                        $renamed++;
                    }
                } elseif ($stray && $proper) {
                    /* Both came in: the one under the code is the keeper. */
                    $duplicate = Product::find($stray->product_id);

                    $stray->delete();
                    $offers++;

                    if ($duplicate && ! $duplicate->offers()->exists()) {
                        $duplicate->update(['status' => Product::STATUS_ARCHIVED]);
                        $retired++;
                    }
                }

                $rows += SupplierStock::where('supplier_id', $supplier->id)
                    ->where('external_id', $was)
                    ->delete();
            });
        }

        return compact('offers', 'renamed', 'retired', 'rows');
    }

    protected function address(SupplierStock $row): ?string
    {
        $url = ((array) ($row->data ?? []))['url'] ?? null;

        return $url ? rtrim(strtolower(trim((string) $url)), '/') : null;
    }

    protected function looksLikeUrl(string $key): bool
    {
        return str_starts_with(strtolower($key), 'http');
    }
}
