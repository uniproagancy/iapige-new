<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const REPLACEMENTS = [
        'alneo.com.ge' => 'iapi.ge',
        'alneo.ge' => 'iapi.ge',
        'Alneo' => 'IAPI.GE',
    ];

    public function up(): void
    {
        $supplier = Supplier::where('code', 'alneo')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        if (! empty($config['replace'])) {
            return;
        }

        $config['replace'] = self::REPLACEMENTS;

        $supplier->update(['config' => $config]);
    }

    public function down(): void
    {
        $supplier = Supplier::where('code', 'alneo')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        unset($config['replace']);

        $supplier->update(['config' => $config]);
    }
};
