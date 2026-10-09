<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

/**
 * Zoommer only adds what a customer could collect in Tbilisi.
 *
 * The flag was added to the seeder, which only runs on a fresh database — so
 * the live supplier row never gained it and products with nothing on the
 * shelf kept arriving. The same drift stopped Ingco's stock column working
 * earlier, and a seeder is the wrong place to fix a row that already exists.
 *
 * Merged rather than written over: the row carries the id range, the worker
 * settings and the column map, none of which this is entitled to touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->set(true);
    }

    public function down(): void
    {
        $this->set(false);
    }

    protected function set(bool $value): void
    {
        $supplier = Supplier::where('code', 'zoommer')->first();

        if (! $supplier) {
            return;
        }

        $supplier->update([
            'config' => array_merge($supplier->config ?? [], ['require_stock' => $value]),
        ]);
    }
};
