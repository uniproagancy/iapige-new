<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentManager;
use Illuminate\Console\Command;

class CheckPayments extends Command
{
    protected $signature = 'payments:check {--hours=48 : how far back to look}';

    protected $description = 'Confirm pending payments with their providers';

    public function handle(PaymentManager $payments): int
    {
        $pending = PaymentTransaction::where('status', PaymentTransaction::PENDING)
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->with('order')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing pending.');

            return self::SUCCESS;
        }

        $paid = 0;

        foreach ($pending as $transaction) {
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

        $this->info("{$pending->count()} checked, {$paid} confirmed.");

        return self::SUCCESS;
    }
}
