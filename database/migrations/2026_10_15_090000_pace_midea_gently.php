<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

/**
 * Midea was being asked four times a second and started refusing to hurry.
 *
 * The shape of it was unmistakable: fast for the first minute, then twenty
 * seconds a request for the rest of the run. Their shop is small and does not
 * sit behind a CDN, so the pace that passes unnoticed elsewhere is a flood
 * here.
 *
 * Being throttled costs far more than going slowly. Four hundred products at
 * twenty seconds apiece is over two hours; the same four hundred at one every
 * two seconds is thirteen minutes, because nothing ever throttles.
 *
 * Thirty a minute is a guess at where their patience ends — a starting point
 * to tune from, not a measurement. Raise it while the run stays quick; if the
 * twenty seconds come back, it was too high.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->set(30);
    }

    public function down(): void
    {
        $this->set(null);
    }

    protected function set(?int $rate): void
    {
        $supplier = Supplier::where('code', 'midea')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        if ($rate === null) {
            unset($config['rate']);
        } else {
            $config['rate'] = $rate;
        }

        $supplier->update(['config' => $config]);
    }
};
