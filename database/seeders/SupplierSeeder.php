<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Import\Drivers\Zoommer\ZoommerDriver;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'zoommer'], [
            'name' => 'Zoommer',
            'driver' => ZoommerDriver::class,
            'priority' => 10,
            'config' => [
                'base_uri' => env('ZOOMMER_BASE_URI', 'https://api.zoommer.ge'),
                'from' => 1,
                'to' => 1000,
                'locales' => ['ka', 'en'],
                'stock_cities' => ['თბილისი', 'Tbilisi'],
                'stock_units' => 5,
                // the Tbilisi branches are what a customer can collect from
                'require_stock' => true,
                'headers' => [
                    'User-Agent' => env('ZOOMMER_USER_AGENT', ''),
                    'Cookie' => env('ZOOMMER_COOKIE', ''),
                ],
            ],
            'markup' => [
                ['up_to' => 100, 'add' => 30],
                ['up_to' => 500, 'add' => 50],
                ['add' => 100],
            ],
        ]);
    }
}
