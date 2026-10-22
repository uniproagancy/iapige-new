<?php

namespace App\Console\Commands;

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
        $untouched = 0;

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'data'])
            ->lazyById(500)
            ->each(function (SupplierStock $row) use (&$reasons, &$untouched) {
                $data = (array) ($row->data ?? []);

                if ($reason = $data['skipped'] ?? null) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                    return;
                }

                /* Nothing was written on it either way, so it was never tried. */
                if (! isset($data['page_category']) && ! isset($data['page_brand'])) {
                    $untouched++;
                }
            });

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

        if ($untouched) {
            $this->newLine();
            $this->warn("{$untouched} rows were never tried — the run did not reach them.");
            $this->line('  Start the import again; rows already done are simply refreshed.');
        }

        return self::SUCCESS;
    }
}
