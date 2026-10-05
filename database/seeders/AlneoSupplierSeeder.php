<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alneo\AlneoDriver;
use Illuminate\Database\Seeder;

class AlneoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'alneo'], [
            'name'     => 'Alneo',
            'driver'   => AlneoDriver::class,
            'priority' => 70,
            'config'   => [
                'timeout' => 60,
                'locale'  => 'ka',

                /*
                 | The price list, column by column:
                 | A code (the key)  B quantity  C price  D promotional price
                 */
                'columns' => [
                    'key'   => 'A',
                    'stock' => 'B',
                    'cost'  => 'C',
                    'sale'  => 'D',
                ],

                /*
                 | Added to the shop's own retail price when the spreadsheet
                 | leaves the figure blank — their shelf price already carries
                 | their margin, so selling at it would leave us nothing.
                 */
                'site_price_uplift' => 100,
            ],
            'markup' => [
                ['up_to' => 500, 'percent' => 20],
                ['percent' => 15],
            ],
			'replace' => [
				'alneo.com.ge' => 'iapi.ge',
				'alneo.ge'     => 'iapi.ge',
				'Alneo'        => 'IAPI.GE',
			],
        ]);
    }
}
