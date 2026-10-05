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
            'name'     => 'Alta',
            'driver'   => AltaDriver::class,
            'priority' => 20,           // higher number = loses a price tie to Zoommer
            'config'   => [
                'timeout'      => 30,
                'locale'       => 'ka',  // this source answers in one language
                'min_quantity' => 2,     // fewer than this is not worth listing
            ],
            'markup' => [
                ['up_to' => 100, 'add' => 30],
                ['up_to' => 500, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
