<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    protected const DRIVERS = [
        'bog_card' => 'bog-card',
        'bog_installment' => 'bog-installment',
        'bog_split' => 'bog-installment',
        'tbc_installment' => 'tbc-installment',
        'credo_installment' => 'credo-installment',
        'credo_split' => 'credo-split',
    ];

    public function run(): void
    {
        $methods = [
            ['bog_card', '/img/payments/bog-1.png', true, null,
                ['ka' => 'საქართველოს ბანკის ონლაინ გადახდა', 'en' => 'Bank of Georgia — card payment']],

            ['bog_installment', '/img/payments/bog-1.png', true, 300,
                ['ka' => 'საქართველოს ბანკის ონლაინ განვადება', 'en' => 'Bank of Georgia — instalments']],

            ['bog_split', '/img/payments/bog-2.png', true, 100,
                ['ka' => 'საქართველოს ბანკის ნაწილ-ნაწილ', 'en' => 'Bank of Georgia — split payment']],

            ['tbc_installment', '/img/payments/tbc-1.png', true, 300,
                ['ka' => 'TBC ბანკის ონლაინ განვადება', 'en' => 'TBC Bank — instalments']],

            ['credo_installment', '/img/payments/credo-1.png', true, 300,
                ['ka' => 'Credo ონლაინ განვადება', 'en' => 'Credo — instalments']],

            ['credo_split', '/img/payments/credo-1.png', true, 100,
                ['ka' => 'Credo ნაწილ-ნაწილ', 'en' => 'Credo Split']],

            ['cash', null, false, null,
                ['ka' => 'ადგილზე გადახდა', 'en' => 'Cash on delivery']],

            ['transfer', null, false, null,
                ['ka' => 'საბანკო გადარიცხვა', 'en' => 'Bank transfer']],
        ];

        foreach ($methods as $i => [$code, $logo, $online, $min, $names]) {
            $existing = PaymentMethod::where('code', $code)->first();

            $method = PaymentMethod::updateOrCreate(['code' => $code], [
                'logo' => $logo,
                'is_online' => $online,
                'min_total' => $min,
                'sort_order' => $i + 1,
                'driver' => $existing?->driver ?? self::DRIVERS[$code] ?? null,
            ]);

            $method->saveTranslations([
                'ka' => ['name' => $names['ka']],
                'en' => ['name' => $names['en']],
            ]);
        }
    }
}
