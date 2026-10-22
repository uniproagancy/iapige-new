<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\MagiToys\MagiToysDriver;
use Illuminate\Database\Seeder;

class MagiToysSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'magitoys'], [
            'name' => 'Magi Toys',
            'driver' => MagiToysDriver::class,
            'priority' => 70,
            'is_active' => true,
            'config' => [
                'timeout' => 25,
                'connect_timeout' => 10,
                'locale' => 'ka',

                'max_images' => 6,

                /* The sheet says 0 for most lines; nothing is invented on top. */
                'default_stock' => 0,

                /* sku | stock | link | price.
                   689 rows carry no code of their own and the links run past
                   5000 characters, so the key is taken from the address rather
                   than from a column. The category and the brand are not in
                   the sheet at all — the source page names both. */
                'columns' => [
                    'key' => 'C',
                    'key_as' => 'url',
                    'stock' => 'B',
                    'cost' => 'D',
                    'url' => 'C',
                    'code' => 'A',
                ],

                'key_specs' => ['სიმძლავრე', 'ვოლტაჟი', 'ტიპი', 'მოდელი'],
            ],
            /* The column is the retail price, so there is nothing to add. */
            'markup' => [],
        ]);
    }
}
