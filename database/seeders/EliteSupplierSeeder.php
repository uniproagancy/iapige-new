<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Elite\EliteDriver;
use Illuminate\Database\Seeder;

class EliteSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'elite'], [
            'name'     => 'Elite',
            'driver'   => EliteDriver::class,
            'priority' => 30,
            'config'   => [
                'timeout' => 30,
                'locale'  => 'ka',
                'from'    => 1,
                'to'      => 35000,
            ],
            'markup' => [
                ['up_to' => 100, 'add' => 30],
                ['up_to' => 500, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
