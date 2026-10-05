<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Ingco\IngcoDriver;
use Illuminate\Database\Seeder;

class IngcoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'ingco'], [
            'name'     => 'INGCO',
            'driver'   => IngcoDriver::class,
            'priority' => 60,
            'config'   => [
                'timeout' => 30,
                'locale'  => 'ka',
                'brand'   => 'INGCO',

                // below this the handling costs more than the tool returns
                'min_price' => 120,

                /*
                 | The price list, column by column:
                 |
                 | A model (the key)   B promotional price
                 | C list price        D quantity
                 */
                'columns' => [
                    'key'   => 'A',
                    'sale'  => 'B',
                    'cost'  => 'C',
                    'stock' => 'D',
                ],
            ],
            'markup' => [
                ['up_to' => 200, 'percent' => 25],
                ['percent' => 20],
            ],
        ]);
    }
}
