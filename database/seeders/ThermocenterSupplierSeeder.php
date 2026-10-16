<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Thermocenter\ThermocenterDriver;
use Illuminate\Database\Seeder;

class ThermocenterSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'thermocenter'], [
            'name' => 'Thermocenter',
            'driver' => ThermocenterDriver::class,
            'priority' => 95,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',

                'columns' => [
                    'key' => 'A',
                    'url' => 'B',
                ],

                'scan' => true,
                'catalogue_url' => 'https://thermocenter.ge/თერმო-ცენტრი-კატეგორიები',
                'scan_per_page' => 250,
                'scan_max_pages' => 40,

                'min_cost' => 100,

                'replace' => [
                    'www.thermocenter.ge' => 'iapi.ge',
                    'thermocenter.ge' => 'iapi.ge',
                    'თერმოცენტრი' => 'iapi.ge',
                ],
            ],

            'markup' => [
                ['percent' => 20],
            ],
        ]);
    }
}
