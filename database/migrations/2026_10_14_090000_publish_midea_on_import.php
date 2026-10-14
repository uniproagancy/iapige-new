<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

/**
 * Midea's products were imported and then sat in a drafts list.
 *
 * An import lands as a draft so somebody can look at it first, which is right
 * for a feed that arrives half-described. Midea's does not: its search answers
 * with the title, the gallery and the description, so three hundred finished
 * products were waiting to be ticked one by one, and the shop showed one of
 * them.
 *
 * Merged into whatever the row already holds, because the live row carries the
 * column map, the listings and the worker settings, and a seeder only runs on
 * a fresh database — which is how this row drifted in the first place.
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
        $supplier = Supplier::where('code', 'midea')->first();

        if (! $supplier) {
            return;
        }

        $supplier->update([
            'config' => array_merge($supplier->config ?? [], ['publish_on_import' => $value]),
        ]);
    }
};
