<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\PriceListLoader;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads a supplier's price list from a file already on this machine.
 *
 * The reading itself lives in PriceListLoader, because the admin uploads the
 * same spreadsheets through the browser and the two must not drift apart.
 */
class ImportSupplierFile extends Command
{
    protected $signature = 'import:file
                            {supplier : the supplier code}
                            {file : path to .xlsx or .csv}
                            {--sheet=1}
                            {--skip-rows=1 : header rows to ignore}';

    protected $description = 'Import a supplier price list (xlsx or csv)';

    public function handle(PriceListLoader $loader): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->firstOrFail();

        try {
            $result = $loader->load(
                $supplier,
                $this->argument('file'),
                (int) $this->option('sheet'),
                (int) $this->option('skip-rows'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result['read']) {
            $this->warn('The file contained no usable rows.');

            return self::SUCCESS;
        }

        if ($result['missing_url']) {
            $this->warn("{$result['missing_url']} rows carry no link and will be skipped.");
        }

        $this->info("{$supplier->code}: {$result['read']} rows loaded, {$result['zeroed']} zeroed out.");
        $this->line("Next: php artisan import:run {$supplier->code}");

        return self::SUCCESS;
    }
}
