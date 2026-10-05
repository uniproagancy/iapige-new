<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['bog_card', '/img/pay/bog.svg', true, null,
                ['ka' => 'საქართველოს ბანკის ონლაინ გადახდა', 'en' => 'Bank of Georgia — card payment']],

            ['bog_installment', '/img/pay/bog.svg', true, 300,
                ['ka' => 'საქართველოს ბანკის ონლაინ განვადება', 'en' => 'Bank of Georgia — instalments']],

            ['bog_split', '/img/pay/bog-split.svg', true, 100,
                ['ka' => 'საქართველოს ბანკის ნაწილ-ნაწილ', 'en' => 'Bank of Georgia — split payment']],

            ['tbc_installment', '/img/pay/tbc.svg', true, 300,
                ['ka' => 'TBC ბანკის ონლაინ განვადება', 'en' => 'TBC Bank — instalments']],

            ['credo_installment', '/img/pay/credo.svg', true, 300,
                ['ka' => 'Credo ონლაინ განვადება', 'en' => 'Credo — instalments']],

            ['cash', '/img/pay/cash.svg', false, null,
                ['ka' => 'ადგილზე გადახდა', 'en' => 'Cash on delivery']],

            ['transfer', '/img/pay/transfer.svg', false, null,
                ['ka' => 'საბანკო გადარიცხვა', 'en' => 'Bank transfer']],
        ];

        foreach ($methods as $i => [$code, $logo, $online, $min, $names]) {
            $method = PaymentMethod::updateOrCreate(['code' => $code], [
                'logo'       => $logo,
                'is_online'  => $online,
                'min_total'  => $min,
                'sort_order' => $i + 1,
                'driver'     => null,   // filled in when the bank integration lands
            ]);

            $method->saveTranslations([
                'ka' => ['name' => $names['ka']],
                'en' => ['name' => $names['en']],
            ]);
        }
    }
}