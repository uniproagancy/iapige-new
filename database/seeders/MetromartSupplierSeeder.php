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

                /* key | cost | stock | discount price */
                'columns' => [
                    'key' => 'A',
                    'cost' => 'B',
                    'stock' => 'C',
                    'discount_price' => 'D',
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
