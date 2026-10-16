<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Kontakt\KontaktDriver;
use Illuminate\Database\Seeder;

class KontaktSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'kontakt'], [
            'name' => 'Kontakt Home',
            'driver' => KontaktDriver::class,
            'priority' => 80,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',

                'columns' => [
                    'key' => 'A',
                    'url' => 'A',
                    'stock' => 'B',
                    'cost' => 'C',
                    'sale' => 'D',
                ],

                'replace' => [
                    'www.kontakt.ge' => 'iapi.ge',
                    'kontakt.ge' => 'iapi.ge',
                    'შიდა განვადება' => '',
                ],
            ],
            'markup' => [
                ['up_to' => 500, 'percent' => 18],
                ['percent' => 14],
            ],
        ]);
    }
}
