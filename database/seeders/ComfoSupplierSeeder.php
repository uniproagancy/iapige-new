<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Comfo\ComfoDriver;
use Illuminate\Database\Seeder;

class ComfoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'comfo'], [
            'name' => 'Comfo',
            'driver' => ComfoDriver::class,
            'priority' => 90,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',

                'columns' => [
                    'key' => 'A',
                    'stock' => 'B',
                    'url' => 'C',
                ],

                'replace' => [
                    'www.comfo.ge' => 'iapi.ge',
                    'comfo.ge' => 'iapi.ge',
                    'კომფო' => 'iapi.ge',
                ],
            ],

            'markup' => [
                ['up_to' => 100, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
