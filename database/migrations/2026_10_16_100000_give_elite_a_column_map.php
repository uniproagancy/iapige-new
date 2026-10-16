<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $supplier = Supplier::where('code', 'elite')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        if (! empty($config['columns']['key'])) {
            return;
        }

        $config['columns'] = array_merge($config['columns'] ?? [], ['key' => 'A']);

        $supplier->update(['config' => $config]);
    }

    public function down(): void
    {
        $supplier = Supplier::where('code', 'elite')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        unset($config['columns']);

        $supplier->update(['config' => $config]);
    }
};
