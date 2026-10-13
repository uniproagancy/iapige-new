<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

/**
 * INGCO was reporting one of everything.
 *
 * Its price list carries the quantity in column D and the seeder has said so
 * for a long time — but a seeder only runs on a fresh database, and the live
 * row was created before that line existed. So the column map it actually
 * holds names the model, the two prices and nothing else.
 *
 * PriceListLoader reads a missing stock column as "this supplier does not
 * report stock", which is the right reading for the suppliers that genuinely
 * do not, and counts every row as one available. Which is why a tool with
 * fifteen in the file showed one in the admin, while its price — read from the
 * columns that are mapped — was perfectly correct. Nothing looked broken.
 *
 * min_stock has never reached the live row either, so the rule about not
 * selling the last few of a line has never once applied.
 *
 * Merged rather than written over, and only where something is missing: the
 * row may carry worker settings or a corrected column letter that this has no
 * business touching.
 */
return new class extends Migration
{
    public function up(): void
    {
        $supplier = Supplier::where('code', 'ingco')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        $config['columns'] = array_merge(
            ['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'D'],
            $config['columns'] ?? [],
        );

        // array_merge keeps what is already there, so a map that was corrected
        // by hand survives and only the absent stock column is added
        $config['columns']['stock'] ??= 'D';
        $config['min_stock'] ??= 5;
        $config['min_price'] ??= 120;

        $supplier->update(['config' => $config]);
    }

    public function down(): void
    {
        $supplier = Supplier::where('code', 'ingco')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];
        unset($config['columns']['stock']);

        $supplier->update(['config' => $config]);
    }
};
