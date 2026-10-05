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
            'name'     => 'Midea',
            'driver'   => MideaDriver::class,
            'priority' => 40,
            'config'   => [
                'locale' => 'ka',
                'brand'  => 'Midea',

                /*
                 | The price list, column by column:
                 |
                 | A კატეგორია   B მოდელი      C შტრიხკოდი   D აღწერა
                 | E საცალო      F საცალო აქცია  G სადილერო აქცია
                 | H ნაშთი       I/J გარანტია    K www.midea.ge (the site's model)
                 */
                'columns' => [
                    'key'         => 'C',   // barcode — the identity of a row
                    'category'    => 'A',
                    'model'       => 'B',
                    'description' => 'D',
                    'retail'      => 'E',
                    'retail_sale' => 'F',
                    'cost'        => 'G',
                    'stock'       => 'H',
                    'warranty'    => 'I',
                    'site_model'  => 'K',
                ],

                // 'retail' sells at Midea's own promotional price; 'dealer' applies our markup
                'price_from' => 'retail',

                // the site is only asked for photographs and a few specs
                'read_site'         => true,
                'read_product_page' => false,
                'max_pages'         => 30,
                'timeout'           => 30,

                'listings' => [
                    '/ka/products/kondicioneri/split-sistema/all/1/1/',
                    '/ka/products/sarecxi-manqana/sarecxi-manqana-1/all/1/1/',
                    '/ka/products/macivari/ertkameriani-macivari/all/1/1/',
                    '/ka/products/wylis-dispenseri/wylis-dispenseri-1/all/1/1/',
                    '/ka/products/gamwovi/chasashenebeli-gamwovi/all/1/1/',
                    '/ka/products/wylis-gamacxelebeli/centraluri-gatbobis-qvabi/all/1/1/',
                ],
            ],

            // unused while price_from is 'retail'; kept for when it is not
            'markup' => [
                ['up_to' => 500, 'percent' => 25],
                ['percent' => 20],
            ],
        ]);
    }
}
