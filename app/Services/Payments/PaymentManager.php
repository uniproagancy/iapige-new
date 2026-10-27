<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\CallbackRequest;
use App\Models\PaymentTransaction;
use App\Models\PaymentMethod;
use App\Models\QuickPaymentStart;
use App\Services\Facebook\Pixel;
use App\Services\Payments\Drivers\BogCard;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\Drivers\CredoInstallment;
use App\Services\Payments\Drivers\CredoSplit;
use App\Services\Payments\Drivers\TbcInstallment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentManager
{
    protected array $drivers = [
        'bog-installment' => BogInstallment::class,
        'bog-card' => BogCard::class,
        'credo-installment' => CredoInstallment::class,
        'credo-split' => CredoSplit::class,
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

    public function start(Order $order, string $driver, array $options = []): string
    {
        $transaction = $this->startTransaction($order, $driver, $options);

        if (! $transaction->redirect_url) {
            throw new RuntimeException('The payment provider returned no redirect URL.');
        }

        return $transaction->redirect_url;
    }

    public function startTransaction(Order $order, string $driver, array $options = []): PaymentTransaction
    {
        if (! CallbackRequest::where('order_id', $order->id)->whereNotNull('submission_key')->exists()) {
            return $this->driver($driver)->start($order, $options);
        }

        $options = $this->normalOptions($driver, $options);
        $amount = round((float) $order->total * ($driver === 'bog-installment' && $options['type'] === BogInstallment::STANDARD
            ? 1 + (float) config('bog.installment.handling_fee', 0.05) : 1), 2);
        $hash = hash('sha256', json_encode([$driver, round((float) $order->total, 2), $amount, $options], JSON_THROW_ON_ERROR));

        $claim = DB::transaction(function () use ($order, $driver, $options, $amount, $hash) {
            $current = Order::lockForUpdate()->findOrFail($order->id);
            if ($current->is_paid || $current->status === Order::COMPLETED || $current->status === Order::CANCELLED) {
                throw new RuntimeException(__('checkout.already_paid'));
            }
            if (round((float) $current->total, 2) !== round((float) $order->total, 2)) {
                throw new RuntimeException(__('quick_order.price_changed'));
            }
            $method = PaymentMethod::active()->where('code', $current->payment)->first();
            if (! $method || ! $method->is_online || $method->driver !== $driver || ! $method->fitsTotal((float) $current->total)
                || ($driver === 'bog-installment' && ($method->installment_type ?: BogInstallment::STANDARD) !== $options['type'])) {
                throw new RuntimeException(__('checkout.payment_unavailable'));
            }
            if ($current->transactions()->where('status', PaymentTransaction::SUCCESS)->exists()) {
                throw new RuntimeException(__('checkout.already_paid'));
            }

            $attempts = QuickPaymentStart::where('order_id', $order->id)
                ->whereIn('status', ['starting', 'pending', 'unknown'])->lockForUpdate()->latest('id')->get();
            foreach ($attempts as $attempt) {
                $transaction = $attempt->transaction;
                if (! $transaction && hash_equals($attempt->request_hash, $hash)) {
                    $recoverable = $current->transactions()->where('created_at', '>=', $attempt->started_at)->get();
                    if ($recoverable->count() === 1 && $this->compatible($recoverable->first(), $driver, $options, $amount)) {
                        $transaction = $recoverable->first();
                        $attempt->update(['payment_transaction_id' => $transaction->id, 'status' => 'pending']);
                    }
                }
                if ($transaction && in_array($transaction->status, [PaymentTransaction::FAILED, PaymentTransaction::CANCELLED], true)) {
                    $attempt->update(['status' => 'failed']);
                    continue;
                }
                if ($transaction && $transaction->status === PaymentTransaction::PENDING
                    && hash_equals($attempt->request_hash, $hash) && $this->usableRedirect($transaction)) {
                    return ['transaction' => $transaction];
                }

                // A timeout can occur after the bank accepted an application. Time alone never authorizes another one.
                throw new RuntimeException(__('quick_order.payment_uncertain'));
            }

            // A provider transaction can survive a failed ledger update; recover it rather than starting again.
            $pending = $current->transactions()->where('status', PaymentTransaction::PENDING)->latest('id')->first();
            if ($pending) {
                if (! $this->compatible($pending, $driver, $options, $amount) || ! $this->usableRedirect($pending)) {
                    throw new RuntimeException(__('quick_order.payment_uncertain'));
                }
                QuickPaymentStart::create(['order_id' => $order->id, 'driver' => $driver, 'request_hash' => $hash,
                    'options' => $options, 'amount' => $amount, 'status' => 'pending',
                    'payment_transaction_id' => $pending->id, 'started_at' => $pending->created_at]);

                return ['transaction' => $pending];
            }

            return ['attempt' => QuickPaymentStart::create(['order_id' => $order->id, 'driver' => $driver,
                'request_hash' => $hash, 'options' => $options, 'amount' => $amount,
                'status' => 'starting', 'started_at' => now()])];
        });

        if (isset($claim['transaction'])) {
            return $claim['transaction'];
        }
        $attempt = $claim['attempt'];
        try {
            $transaction = $this->driver($driver)->start($order->fresh('items.product.images'), $options);
            if (! $transaction->redirect_url) {
                throw new RuntimeException(__('checkout.payment_failed'));
            }
            $attempt->update(['payment_transaction_id' => $transaction->id, 'status' => 'pending']);

            return $transaction;
        } catch (\Throwable $e) {
            $attempt->update(['status' => 'unknown', 'failure_class' => get_class($e)]);
            throw new RuntimeException(__('quick_order.payment_uncertain'), previous: $e);
        }
    }

    protected function normalOptions(string $driver, array $options): array
    {
        if ($driver !== 'bog-installment') {
            return $options;
        }
        $months = (int) ($options['months'] ?? 12);
        $type = (string) ($options['type'] ?? BogInstallment::STANDARD);
        if ($months < 1 || $months > 48 || ! in_array($type, [BogInstallment::STANDARD, BogInstallment::ZERO], true)) {
            throw new RuntimeException(__('checkout.payment_failed'));
        }

        return ['months' => $months, 'type' => $type];
    }

    protected function compatible(PaymentTransaction $transaction, string $driver, array $options, float $amount): bool
    {
        if ($transaction->driver !== $driver || round((float) $transaction->amount, 2) !== $amount) {
            return false;
        }

        return $driver !== 'bog-installment' || ((int) $transaction->months === $options['months']
            && data_get($transaction->request, 'installment_type') === $options['type']);
    }

    protected function usableRedirect(PaymentTransaction $transaction): bool
    {
        if (! $transaction->redirect_url || $transaction->created_at->lt(now()->subMinutes(15))) {
            return false;
        }
        parse_str((string) parse_url($transaction->redirect_url, PHP_URL_QUERY), $query);

        return ! isset($query['expires']) || (int) $query['expires'] > time();
    }

    public function settle(PaymentTransaction $transaction, bool $paid): void
    {
        $order = $transaction->order;

        if (! $order) {
            return;
        }

        $report = false;
        DB::transaction(function () use ($transaction, $order, $paid, &$report) {
            $current = PaymentTransaction::lockForUpdate()->findOrFail($transaction->id);
            $currentOrder = Order::lockForUpdate()->findOrFail($order->id);
            if (! $paid && $current->status === PaymentTransaction::SUCCESS) {
                return;
            }

            $current->update([
                'status' => $paid ? PaymentTransaction::SUCCESS : PaymentTransaction::FAILED,
                'checked_at' => now(),
                'paid_at' => $paid ? ($current->paid_at ?? now()) : null,
            ]);
            $report = $paid;
            if (! $paid || $currentOrder->is_paid) {
                return;
            }

            $currentOrder->update([
                'is_paid' => true,
                'paid_at' => now(),
                'payment_status' => 'paid',
                'installment_months' => $current->months,
            ]);
            $currentOrder->events()->create([
                'type' => 'payment',
                'note' => __('order.paid_via', ['driver' => $current->driver]),
            ]);
        });

        if ($report) {
            Log::channel('payments')->info('payment settled', [
                'order' => $order->number,
                'driver' => $transaction->driver,
                'transaction' => $transaction->id,
            ]);

            $this->reportPurchase($order->fresh('items'));
        }
    }

    protected function reportPurchase(?Order $order): void
    {
        if ($order) {
            app(Pixel::class)->reportPurchase($order);
        }
    }
}
