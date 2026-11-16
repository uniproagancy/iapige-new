<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportStockFile extends Command
{
    protected $signature = 'import:stock-file
                            {supplier : the supplier code}
                            {file : path to the .xlsx file}
                            {--barcode-column=A}
                            {--quantity-column= : leave empty when the file only lists barcodes}
                            {--default-quantity=5 : used when the file carries no quantities}
                            {--skip-rows=0 : header rows to ignore}';

    protected $description = 'Import a stock spreadsheet (barcodes, optionally quantities)';

    public function handle(ImportManager $imports): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->firstOrFail();
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $barcodeColumn = strtoupper($this->option('barcode-column'));
        $quantityColumn = $this->option('quantity-column') ? strtoupper($this->option('quantity-column')) : null;
        $default = (int) $this->option('default-quantity');
        $skip = (int) $this->option('skip-rows');

        $now = now();
        $rows = [];
        $read = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $index = $row->getRowIndex();

            if ($index <= $skip) {
                continue;
            }

            $barcode = trim((string) $sheet->getCell($barcodeColumn.$index)->getValue());

            if ($barcode === '') {
                continue;
            }

            $quantity = $quantityColumn
                ? (int) $sheet->getCell($quantityColumn.$index)->getValue()
                : $default;

            $rows[] = [
                'supplier_id' => $supplier->id,
                'external_id' => $barcode,
                'quantity' => max(0, $quantity),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $read++;
        }

        if (! $rows) {
            $this->warn('The file contained no barcodes.');

            return self::SUCCESS;
        }

        $fresh = array_flip(array_column($rows, 'external_id'));
        $gone = SupplierStock::where('supplier_id', $supplier->id)
            ->where('quantity', '>', 0)
            ->pluck('external_id')
            ->reject(fn ($id) => isset($fresh[(string) $id]))
            ->all();

        /* The old file is cleared and this one written in its place, in one
           go so the shop never reads the table half-empty. */
        DB::transaction(function () use ($supplier, $rows) {
            SupplierStock::where('supplier_id', $supplier->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                SupplierStock::upsert($chunk, ['supplier_id', 'external_id'], ['quantity', 'synced_at', 'updated_at']);
            }
        });

        $offers = $imports->applyStockFile($supplier, $gone);

        $this->info("{$supplier->code}: {$read} barcodes loaded, ".count($gone)." gone from the file, {$offers} offers updated on the site.");

        return self::SUCCESS;
    }
}
