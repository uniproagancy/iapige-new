<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\HasStockFeed;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;

/**
 * Refreshes supplier stock feeds. Cheap enough to run hourly, unlike a full
 * catalogue import — and it is what keeps "in stock" honest between imports.
 */
class RefreshStock extends Command
{
    protected $signature = 'import:stock {supplier? : the supplier code} {--all}';

    protected $description = 'Refresh the stock feed of one supplier or all of them';

    public function handle(ImportManager $manager): int
    {
        $suppliers = $this->option('all')
            ? Supplier::active()->get()
            : Supplier::where('code', $this->argument('supplier'))->get();

        if ($suppliers->isEmpty()) {
            $this->error('No supplier matched. Pass a code or use --all.');

            return self::FAILURE;
        }

        foreach ($suppliers as $supplier) {
            $driver = $manager->driver($supplier);

            if (! $driver instanceof HasStockFeed) {
                $this->line("{$supplier->code}: no stock feed, skipped");

                continue;
            }

            $count = $driver->refreshStock();
            $this->info("{$supplier->code}: {$count} stock rows");
        }

        return self::SUCCESS;
    }
}
