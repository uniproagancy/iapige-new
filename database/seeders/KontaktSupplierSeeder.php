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
            'name'     => 'Kontakt Home',
            'driver'   => KontaktDriver::class,
            'priority' => 80,
            'config'   => [
                'timeout' => 30,
                'locale'  => 'ka',

                /*
                 | The price list, column by column. The product's address is a
                 | hyperlink behind column A rather than a column of its own.
                 */
                'columns' => [
                    'key'   => 'A',
                    'url'   => 'A',
                    'stock' => 'B',
                    'cost'  => 'C',
                    'sale'  => 'D',
                ],

                // their shop out of our copy
                'replace' => [
                    'www.kontakt.ge' => 'elio.ge',
                    'kontakt.ge'     => 'elio.ge',
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
