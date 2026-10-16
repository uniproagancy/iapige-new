<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Database\Seeder;

class MideaSupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'midea'], [
            'name' => 'Midea',
            'driver' => MideaDriver::class,
            'priority' => 40,
            'config' => [
                'locale' => 'ka',
                'brand' => 'Midea',

                'columns' => [
                    'key' => 'C',
                    'category' => 'A',
                    'model' => 'B',
                    'description' => 'D',
                    'retail' => 'E',
                    'retail_sale' => 'F',
                    'cost' => 'G',
                    'stock' => 'H',
                    'warranty' => 'I',
                    'site_model' => 'K',
                ],

                'price_from' => 'retail',

                'publish_on_import' => true,

                'read_site' => true,
                'read_product_page' => false,
                'max_pages' => 30,
                'timeout' => 30,

                'rate' => 30,

                'listings' => [
                    '/ka/products/kondicioneri/split-sistema/all/1/1/',
                    '/ka/products/sarecxi-manqana/sarecxi-manqana-1/all/1/1/',
                    '/ka/products/macivari/ertkameriani-macivari/all/1/1/',
                    '/ka/products/wylis-dispenseri/wylis-dispenseri-1/all/1/1/',
                    '/ka/products/gamwovi/chasashenebeli-gamwovi/all/1/1/',
                    '/ka/products/wylis-gamacxelebeli/centraluri-gatbobis-qvabi/all/1/1/',
                ],
            ],

            'markup' => [
                ['up_to' => 500, 'percent' => 25],
                ['percent' => 20],
            ],
        ]);
    }
}
