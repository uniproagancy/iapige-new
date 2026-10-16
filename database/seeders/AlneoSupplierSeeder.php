<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alneo\AlneoDriver;
use Illuminate\Database\Seeder;

class AlneoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'alneo'], [
            'name' => 'Alneo',
            'driver' => AlneoDriver::class,
            'priority' => 70,
            'config' => [
                'timeout' => 60,
                'locale' => 'ka',

                'columns' => [
                    'key' => 'A',
                    'stock' => 'B',
                    'cost' => 'C',
                    'sale' => 'D',
                ],

                'site_price_uplift' => 100,

                'replace' => [
                    'alneo.com.ge' => 'iapi.ge',
                    'alneo.ge' => 'iapi.ge',
                    'Alneo' => 'IAPI.GE',
                ],
            ],
            'markup' => [
                ['up_to' => 500, 'percent' => 20],
                ['percent' => 15],
            ],
        ]);
    }
}
