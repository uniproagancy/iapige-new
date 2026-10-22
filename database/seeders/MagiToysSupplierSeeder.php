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

                /* The rows have no code of their own on 689 lines, and the
                   links run to 390 characters, so the key is taken from the
                   address rather than from a column. */
                'columns' => [
                    'key' => 'C',
                    'key_as' => 'url',
                    'stock' => 'B',
                    'cost' => 'D',
                    'url' => 'C',
                    'code' => 'A',
                    'category' => 'E',
                    'brand' => 'F',
                ],

                'key_specs' => ['სიმძლავრე', 'ვოლტაჟი', 'ტიპი', 'მოდელი'],
            ],
            /* The column is the retail price, so there is nothing to add. */
            'markup' => [],
        ]);
    }
}
