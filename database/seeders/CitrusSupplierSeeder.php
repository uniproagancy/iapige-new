<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Citrus\CitrusDriver;
use Illuminate\Database\Seeder;

class CitrusSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'citrus'], [
            'name' => 'Citrus',
            'driver' => CitrusDriver::class,
            'priority' => 70,
            'config' => [
                'timeout' => 30,
                'locale' => 'ka',

                /*
                 | No column map: there is no price list. Products are added by
                 | pasting their addresses, and every lookup brings the current
                 | price and stock with it.
                 */

                // their shop out of our copy
                'replace' => [
                    'www.citrus.ge' => 'iapi.ge',
                    'citrus.ge' => 'iapi.ge',
                    'ციტრუსი' => 'iapi.ge',
                ],
            ],
            /*
             | No margin, on purpose.
             |
             | Citrus is here to bring the content — the name, the
             | description, the specifications and the photographs — and the
             | price is set by hand afterwards. A markup would quietly put a
             | figure on the shelf that nobody chose, and the first anyone
             | would know of it is a customer paying it.
             |
             | An empty list leaves Supplier::markup() returning the cost it
             | was given, so the product arrives at Citrus's own price and
             | waits to be priced properly.
             */
            'markup' => [],
        ]);
    }
}
