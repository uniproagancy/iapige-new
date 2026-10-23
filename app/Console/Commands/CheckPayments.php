<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentManager;
use Illuminate\Console\Command;

class CheckPayments extends Command
{
    protected $signature = 'payments:check {--hours= : how far back to look, in hours}';

    protected $description = 'Confirm pending payments with their providers';

    public function handle(PaymentManager $payments): int
    {
        $hours = (int) ($this->option('hours') ?: config('shop.payment_check_hours', 168));

        $query = PaymentTransaction::where('status', PaymentTransaction::PENDING)
            ->where('created_at', '>=', now()->subHours($hours))
            ->with('order');

        $waiting = (clone $query)->count();

        if (! $waiting) {
            $this->info('Nothing pending.');

            return self::SUCCESS;
        }

        $paid = 0;

        /* There can be thousands of these; they are walked rather than loaded. */
        foreach ($query->lazyById(200) as $transaction) {
            if (! $payments->has($transaction->driver)) {
                continue;
            }

            try {
                $confirmed = $payments->driver($transaction->driver)->confirm($transaction);

                if ($confirmed) {
                    $payments->settle($transaction, true);
                    $paid++;
                }
            } catch (\Throwable $e) {
                $this->warn("#{$transaction->id}: {$e->getMessage()}");
            }
        }

        $this->info("{$waiting} checked, {$paid} confirmed.");

        return self::SUCCESS;
    }
}
