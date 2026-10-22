<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected const NAMES = [
        'ka' => 'Credo ნაწილ-ნაწილ',
        'en' => 'Credo Split',
    ];

    public function up(): void
    {
        $existing = DB::table('payment_methods')->where('code', 'credo_split')->first();

        $configured = (bool) config('credo.split.merchant_id');

        $row = [
            'logo' => '/img/payments/credo-1.png',
            'is_online' => true,
            'min_total' => 100,
            'sort_order' => 6,
            'driver' => $existing?->driver ?? ($configured ? 'credo-split' : null),
            'is_active' => $existing ? (bool) $existing->is_active : $configured,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('payment_methods')->where('id', $existing->id)->update($row);

            return;
        }

        $id = DB::table('payment_methods')->insertGetId($row + [
            'code' => 'credo_split',
            'created_at' => now(),
        ]);

        /* On a fresh install the languages are seeded after the migrations run,
           so a name is only written for a locale that already exists. */
        foreach (self::NAMES as $locale => $name) {
            if (! DB::table('languages')->where('code', $locale)->exists()) {
                continue;
            }

            DB::table('payment_method_translations')->updateOrInsert(
                ['payment_method_id' => $id, 'locale' => $locale],
                ['name' => $name],
            );
        }
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('code', 'credo_split')->delete();
    }
};
