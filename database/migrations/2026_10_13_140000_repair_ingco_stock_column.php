<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

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
