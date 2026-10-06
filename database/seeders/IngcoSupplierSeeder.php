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
            'name' => 'INGCO',
            'driver' => IngcoDriver::class,
            'priority' => 60,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',
                'brand' => 'INGCO',

                /*
                 | Below this the handling costs more than the tool returns.
                 | Measured against the promotional price in column B, which is
                 | what the supplier actually charges — not the list price in C.
                 */
                'min_price' => 120,

                /*
                 | Fewer than this in stock reads as unavailable rather than
                 | being sold: the last two of a line are the ones that turn
                 | into a cancelled order, and the list is a day old by the time
                 | anybody buys. The product stays, so it returns on its own
                 | when the supplier restocks.
                 */
                'min_stock' => 5,

                /*
                 | The price list, column by column:
                 |
                 | A model (the key)   B promotional price
                 | C list price        D quantity
                 */
                'columns' => [
                    'key' => 'A',
                    'sale' => 'B',
                    'cost' => 'C',
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
