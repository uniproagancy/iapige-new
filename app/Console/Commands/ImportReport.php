<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Console\Command;

/*
 | Accounts for a run: of everything the price list named, what reached the
 | shop, what is still waiting, and what was turned away and why.
 */
class ImportReport extends Command
{
    protected $signature = 'import:report {supplier : the supplier code}';

    protected $description = 'Say what became of each row of a supplier price list';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        $rows = SupplierStock::where('supplier_id', $supplier->id)->count();

        if (! $rows) {
            $this->info('No price list has been loaded for this supplier.');

            return self::SUCCESS;
        }

        $offers = ProductOffer::where('supplier_id', $supplier->id)->count();

        $reasons = [];

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'data'])
            ->lazyById(500)
            ->each(function (SupplierStock $row) use (&$reasons) {
                if ($reason = ((array) ($row->data ?? []))['skipped'] ?? null) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            });

        /* A row with neither a product behind it nor a reason against it was
           never reached — the run stopped before it. */
        $untouched = max(0, $rows - $offers - array_sum($reasons));

        arsort($reasons);

        $this->table(['', 'rows'], [
            ['in the price list', $rows],
            ['reached the shop', $offers],
            ['turned away', array_sum($reasons)],
            ['never tried', $untouched],
        ]);

        if ($reasons) {
            $this->newLine();
            $this->line('  why rows were turned away:');

            $this->table(['reason', 'rows'], array_map(
                fn ($reason, $count) => [$reason, $count],
                array_keys($reasons),
                $reasons,
            ));
        }

        $this->prices($supplier);

        if ($untouched) {
            $this->newLine();
            $this->warn("{$untouched} rows were never tried — the run did not reach them.");
            $this->line('  Start the import again; rows already done are simply refreshed.');
        }

        return self::SUCCESS;
    }

    /*
     | Where a struck-through price is lost. It has to survive three steps: the
     | column has to be mapped, the row has to carry it, and the offer has to
     | keep it — only then does the card show it.
     */
    protected function prices(Supplier $supplier): void
    {
        $column = $supplier->config['columns']['discount_price'] ?? null;

        $this->newLine();
        $this->line('  struck-through prices');

        if (! $column) {
            $this->warn('  no discount_price column is mapped for this supplier.');
            $this->line('  Run the supplier seeder again so the new column map is stored.');

            return;
        }

        $rows = SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'data'])
            ->lazyById(500)
            ->filter(fn (SupplierStock $row) => trim((string) (($row->data ?? [])['discount_price'] ?? '')) !== '')
            ->count();

        $offers = ProductOffer::where('supplier_id', $supplier->id)
            ->whereNotNull('old_cost_price')
            ->where('old_cost_price', '>', 0)
            ->count();

        $shown = Product::whereIn('id', ProductOffer::where('supplier_id', $supplier->id)->select('product_id'))
            ->whereNotNull('old_price')
            ->whereColumn('old_price', '>', 'price')
            ->count();

        $this->table(['', 'rows'], [
            ['column '.$column.' read from the sheet', $rows],
            ['kept on the offer', $offers],
            ['shown on the card', $shown],
        ]);

        if ($rows && ! $offers) {
            $this->warn('  The sheet carries them but no offer does — run the import again.');
        }

        if ($offers && ! $shown) {
            $this->warn('  The offers carry them but no card shows one.');
            $this->line('  A price is only struck through when it is above the selling price,');
            $this->line('  and a product whose price is locked keeps the price it was given.');
        }
    }
}
