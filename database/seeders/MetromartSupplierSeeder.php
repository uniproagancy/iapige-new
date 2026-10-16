<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Metromart\MetromartDriver;
use Illuminate\Database\Seeder;

class MetromartSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'metromart'], [
            'name' => 'Metromart',
            'driver' => MetromartDriver::class,
            'priority' => 90,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',

                'columns' => [
                    'key' => 'A',
                    'cost' => 'B',
                    'stock' => 'C',
                ],

                'site_price_uplift' => 0.20,
            ],
            'markup' => [
                ['up_to' => 500, 'percent' => 18],
                ['percent' => 14],
            ],
        ]);
    }
}
