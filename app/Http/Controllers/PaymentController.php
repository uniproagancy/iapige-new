<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentCallback;
use App\Services\Payments\PaymentCheck;
use App\Services\Payments\PaymentManager;
use App\Support\Redact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    public function callback(Request $request, string $driver)
    {
        $started = microtime(true);
        $record = PaymentCallback::receive($request, $driver);

        Log::channel('payments')->info('callback received', [
            'driver' => $driver,
            'record' => $record?->id,
            'payload' => Redact::secrets(json_encode($request->all(), JSON_UNESCAPED_UNICODE)),
        ]);

        $outcome = ['outcome' => PaymentCallback::UNKNOWN_DRIVER];

        if ($this->payments->has($driver)) {
            $outcome = ['outcome' => PaymentCallback::UNMATCHED];

            try {
                $provider = $this->payments->driver($driver);
                $transaction = $provider->handleCallback($request->all());

                if ($transaction) {
                    $outcome += [
                        'payment_transaction_id' => $transaction->id,
                        'order_id' => $transaction->order_id,
                        'status_before' => $transaction->status,
                    ];

                    /* The bank is asked; the callback only says it is time to. */
                    $paid = $provider->confirm($transaction);
                    $this->payments->settle($transaction, $paid);

                    $outcome['outcome'] = $paid ? PaymentCallback::PAID : PaymentCallback::NOT_PAID;
                    $outcome['confirmed'] = $paid;
                    $outcome['status_after'] = $transaction->fresh()?->status;
                }
            } catch (\Throwable $e) {
                $outcome['outcome'] = PaymentCallback::FAILED;
                $outcome['error'] = mb_substr($e->getMessage(), 0, 2000);

                Log::channel('payments')->error('callback failed', [
                    'driver' => $driver,
                    'record' => $record?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $record?->finish($outcome + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)]);

        return response()->json(['ok' => true]);
    }

    public function return(Request $request, string $number)
    {
        $order = Order::where('number', $number)->firstOrFail();
        $status = $request->query('status', 'success');

        if ($request->hasValidSignature()) {
            session()->push('placed_orders', $order->id);
        }

        if ($status !== 'success') {
            session()->flash('payment_failed', $status);
        }

        /* The provider has just finished with them, so this is the moment it
           is most likely to have an answer. Asking here means the order is
           usually settled before the page they land on is drawn. */
        if (! $order->is_paid) {
            app(PaymentCheck::class)->ask($order);
        }

        return redirect()->route('order', $order->number);
    }
}
