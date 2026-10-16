<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const SCAN = [
        'scan' => true,
        'catalogue_url' => 'https://thermocenter.ge/თერმო-ცენტრი-კატეგორიები',
        'scan_per_page' => 250,
        'scan_max_pages' => 40,
    ];

    public function up(): void
    {
        $supplier = Supplier::where('code', 'thermocenter')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        if (array_key_exists('scan', $config)) {
            return;
        }

        $supplier->update(['config' => array_merge($config, self::SCAN)]);
    }

    public function down(): void
    {
        $supplier = Supplier::where('code', 'thermocenter')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        foreach (array_keys(self::SCAN) as $key) {
            unset($config[$key]);
        }

        $supplier->update(['config' => $config]);
    }
};
