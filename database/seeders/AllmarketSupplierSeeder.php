<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Allmarket\AllmarketDriver;
use Illuminate\Database\Seeder;

class AllmarketSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'allmarket'], [
            'name'     => 'Allmarket',
            'driver'   => AllmarketDriver::class,
            'priority' => 50,
            'config'   => [
                'timeout' => 60,
                'locale'  => 'ka',
            ],
            'markup' => [
                ['up_to' => 100, 'percent' => 20],
                ['up_to' => 500, 'percent' => 15],
                ['percent' => 12],
            ],
        ]);
    }
}
