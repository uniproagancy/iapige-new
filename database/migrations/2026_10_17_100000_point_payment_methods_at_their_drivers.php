<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $methods = DB::table('payment_methods')->get(['id', 'code', 'driver']);

        foreach ($methods as $method) {
            $wanted = $this->driverFor($method->code);

            if ($wanted === null) {
                continue;
            }

            DB::table('payment_methods')->where('id', $method->id)->update([
                'driver' => $wanted['driver'],
                'is_online' => true,
            ] + ($wanted['type'] ? ['installment_type' => $wanted['type']] : []));
        }

        DB::table('payment_methods')
            ->whereNull('driver')
            ->update(['is_online' => false]);
    }

    protected function driverFor(string $code): ?array
    {
        $code = strtolower($code);

        if (str_contains($code, 'card') && config('bog.payment.secret_key')) {
            return ['driver' => 'bog-card', 'type' => null];
        }

        if (! str_contains($code, 'installment') && ! str_contains($code, 'ganvadeba') && ! str_contains($code, 'split')) {
            return null;
        }

        if (str_contains($code, 'tbc') && config('tbc.installment.merchant_key')) {
            return ['driver' => 'tbc-installment', 'type' => null];
        }

        if (str_contains($code, 'credo') && config('credo.merchant_id')) {
            return ['driver' => 'credo-installment', 'type' => null];
        }

        if (str_contains($code, 'bog')) {
            $zero = str_contains($code, 'zero') || str_contains($code, 'split');

            return ['driver' => 'bog-installment', 'type' => $zero ? 'ZERO' : 'STANDARD'];
        }

        return null;
    }

    public function down(): void {}
};
