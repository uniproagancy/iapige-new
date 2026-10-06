<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Loads a supplier's stock spreadsheet into supplier_stocks.
 *
 * The file is treated as the whole truth: a barcode missing from it is set to
 * zero, so a product that ran out simply stops being available — no deletes,
 * nothing to undo when the next file arrives.
 */
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

    public function handle(): int
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

        foreach (array_chunk($rows, 500) as $chunk) {
            SupplierStock::upsert($chunk, ['supplier_id', 'external_id'], ['quantity', 'synced_at', 'updated_at']);
        }

        // whatever the new file does not mention is out of stock
        $zeroed = SupplierStock::where('supplier_id', $supplier->id)
            ->where('synced_at', '<', $now)
            ->update(['quantity' => 0, 'synced_at' => $now]);

        $this->info("{$supplier->code}: {$read} barcodes loaded, {$zeroed} zeroed out.");
        $this->line('Run import:sync-stock '.$supplier->code.' to apply it to existing offers.');

        return self::SUCCESS;
    }
}
