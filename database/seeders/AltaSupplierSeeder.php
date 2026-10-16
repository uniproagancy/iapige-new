<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaDriver;
use Illuminate\Database\Seeder;

class AltaSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'alta'], [
            'name' => 'Alta',
            'driver' => AltaDriver::class,
            'priority' => 20,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',
                'min_quantity' => 2,
            ],
            'markup' => [
                ['up_to' => 100, 'add' => 30],
                ['up_to' => 500, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
