<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;

/*
 | Asking the bank where an order's payment stands, by hand. The callback and
 | the five-minute sweeper ask the same question on their own; this is for the
 | moment somebody is looking at an order and needs to know now.
 |
 | It answers in words the admin can show, because every caller shows them.
 */
class PaymentCheck
{
    public function __construct(protected PaymentManager $payments) {}

    public function ask(Order $order): array
    {
        $transaction = $order->transactions()
            ->whereIn('status', [PaymentTransaction::PENDING, PaymentTransaction::FAILED])
            ->latest('id')
            ->first();

        if (! $transaction) {
            return $this->no(__('admin.pay_no_transaction'));
        }

        if (! $this->payments->has($transaction->driver)) {
            return $this->no(__('admin.pay_no_driver', ['driver' => $transaction->driver]));
        }

        try {
            $paid = $this->payments->driver($transaction->driver)->confirm($transaction);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('manual check failed', [
                'order' => $order->number,
                'driver' => $transaction->driver,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            /* Not a refusal — the bank simply did not answer. */
            return $this->no(__('admin.pay_check_failed'));
        }

        $this->payments->settle($transaction, $paid);

        return [
            'paid' => $paid,
            'message' => $paid ? __('admin.pay_confirmed') : __('admin.pay_not_yet'),
            'type' => $paid ? 'success' : 'error',
        ];
    }

    protected function no(string $message): array
    {
        return ['paid' => false, 'message' => $message, 'type' => 'error'];
    }
}
