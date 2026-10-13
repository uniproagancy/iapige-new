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

                /*
                 | The price list, column by column: their code and the
                 | product's address. No price and no quantity — the page
                 | carries both.
                 */
                'columns' => [
                    'key' => 'A',
                    'url' => 'B',
                ],

                /*
                 | Below this it is not worth importing.
                 |
                 | Their catalogue runs from air conditioners down to filters
                 | and fittings, and the small end cannot be sold at a margin
                 | that covers delivering it.
                 */
                'min_cost' => 100,

                // their shop out of our copy
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
