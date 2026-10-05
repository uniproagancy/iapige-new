<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\Drivers\BogCard;
use App\Services\Payments\Drivers\CredoInstallment;
use App\Services\Payments\Drivers\TbcInstallment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chooses a driver and keeps the order in step with it.
 *
 * The order's own payment state is written here rather than in the drivers, so
 * a new bank cannot invent its own idea of what "paid" means.
 */
class PaymentManager
{
    /** @var array<string, class-string<PaymentDriver>> */
    protected array $drivers = [
        'bog-installment' => BogInstallment::class,
        'bog-card'     => BogCard::class,
		'credo-installment' => CredoInstallment::class,
		'tbc-installment' => TbcInstallment::class,
    ];

    public function driver(string $name): PaymentDriver
    {
        $class = $this->drivers[$name] ?? null;

        if (! $class) {
            throw new RuntimeException("No payment driver registered for [{$name}].");
        }

        return app($class);
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /** @return string the URL to send the customer to */
    public function start(Order $order, string $driver, array $options = []): string
    {
        $transaction = $this->driver($driver)->start($order, $options);

        if (! $transaction->redirect_url) {
            throw new RuntimeException('The payment provider returned no redirect URL.');
        }

        return $transaction->redirect_url;
    }

    /**
     * Applies a confirmed payment to its order — once.
     *
     * Banks resend callbacks and the scheduler polls, so this is called more
     * often than it acts; the second call must change nothing.
     */
    public function settle(PaymentTransaction $transaction, bool $paid): void
    {
        $order = $transaction->order;

        if (! $order) {
            return;
        }

        DB::transaction(function () use ($transaction, $order, $paid) {
            $transaction->update([
                'status'     => $paid ? PaymentTransaction::SUCCESS : PaymentTransaction::FAILED,
                'checked_at' => now(),
                'paid_at'    => $paid ? ($transaction->paid_at ?? now()) : null,
            ]);

            if (! $paid || $order->is_paid) {
                return;   // nothing to do twice
            }

            $order->update([
                'is_paid'            => true,
                'paid_at'            => now(),
                'payment_status'     => 'paid',
                'installment_months' => $transaction->months,
            ]);

            $order->events()->create([
                'type' => 'payment',
                'note' => __('order.paid_via', ['driver' => $transaction->driver]),
            ]);
        });

        if ($paid) {
            Log::channel('payments')->info('payment settled', [
                'order'       => $order->number,
                'driver'      => $transaction->driver,
                'transaction' => $transaction->id,
            ]);
        }
    }
}
