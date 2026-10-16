<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Ingco\IngcoDriver;
use Illuminate\Database\Seeder;

class IngcoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'ingco'], [
            'name' => 'INGCO',
            'driver' => IngcoDriver::class,
            'priority' => 60,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',
                'brand' => 'INGCO',

                'min_price' => 120,

                'min_stock' => 5,

                'columns' => [
                    'key' => 'A',
                    'sale' => 'B',
                    'cost' => 'C',
                    'stock' => 'D',
                ],
            ],
            'markup' => [
                ['up_to' => 200, 'percent' => 25],
                ['percent' => 20],
            ],
        ]);
    }
}
