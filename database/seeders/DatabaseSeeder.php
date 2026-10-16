<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            PaymentMethodSeeder::class,
            DeliveryCitySeeder::class,
            AttributeSeeder::class,

            SupplierSeeder::class,
            AllmarketSupplierSeeder::class,
            AlneoSupplierSeeder::class,
            AltaSupplierSeeder::class,
            CitrusSupplierSeeder::class,
            ComfoSupplierSeeder::class,
            EliteSupplierSeeder::class,
            IngcoSupplierSeeder::class,
            KontaktSupplierSeeder::class,
            MetromartSupplierSeeder::class,
            MideaSupplierSeeder::class,
            ThermocenterSupplierSeeder::class,
        ]);
    }
}
