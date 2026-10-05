<?php

namespace Database\Seeders;

use App\Models\DeliveryCity;
use Illuminate\Database\Seeder;

class DeliveryCitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['ka' => 'თბილისი', 'en' => 'Tbilisi',  'fee' => 0,  'free_from' => 0,    'days' => 1],
            ['ka' => 'რუსთავი', 'en' => 'Rustavi',  'fee' => 5,  'free_from' => 200,  'days' => 1],
            ['ka' => 'მცხეთა',  'en' => 'Mtskheta', 'fee' => 5,  'free_from' => 200,  'days' => 1],
            ['ka' => 'ბათუმი',  'en' => 'Batumi',   'fee' => 12, 'free_from' => 500,  'days' => 3],
            ['ka' => 'ქუთაისი', 'en' => 'Kutaisi',  'fee' => 10, 'free_from' => 400,  'days' => 2],
            ['ka' => 'გორი',    'en' => 'Gori',     'fee' => 8,  'free_from' => 300,  'days' => 2],
            ['ka' => 'ზუგდიდი', 'en' => 'Zugdidi',  'fee' => 12, 'free_from' => 500,  'days' => 3],
            ['ka' => 'თელავი',  'en' => 'Telavi',   'fee' => 10, 'free_from' => 400,  'days' => 2],
        ];

        foreach ($cities as $i => $city) {
            $model = DeliveryCity::create([
                'fee'        => $city['fee'],
                'free_from'  => $city['free_from'],
                'days'       => $city['days'],
                'sort_order' => $i + 1,
            ]);

            $model->saveTranslations([
                'ka' => ['name' => $city['ka']],
                'en' => ['name' => $city['en']],
            ]);
        }
    }
}