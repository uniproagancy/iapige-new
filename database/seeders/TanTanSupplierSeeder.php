<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\TanTan\TanTanDriver;
use Illuminate\Database\Seeder;

class TanTanSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'tantan'], [
            'name' => 'TanTan Georgia',
            'driver' => TanTanDriver::class,
            'priority' => 70,
            'config' => [
                'locale' => 'ka',
                'timeout' => 60,
                'max_images' => 8,

                // the shop says only "in stock"; this is the count shown for it
                'stock_when_available' => 5,
            ],

            // TanTan's prices are its shop prices; a markup is set in the admin if needed
            'markup' => [],
        ]);
    }
}
