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

                /*
                 | The price list, column by column: a code, a quantity and the
                 | product's address. No price column — the page carries it.
                 */
                'columns' => [
                    'key' => 'A',
                    'stock' => 'B',
                    'url' => 'C',
                ],

                // their shop out of our copy
                'replace' => [
                    'www.comfo.ge' => 'iapi.ge',
                    'comfo.ge' => 'iapi.ge',
                    'კომფო' => 'iapi.ge',
                ],
            ],

            /*
             | A flat margin rather than a percentage.
             |
             | Comfo's range is accessories and small appliances, where a
             | percentage earns nothing on the cheap end: fourteen percent of a
             | thirty lari cable does not cover the delivery of it.
             */
            'markup' => [
                ['up_to' => 100, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
