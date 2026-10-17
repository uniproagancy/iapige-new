<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\PaymentManager;
use App\Support\Redact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    public function callback(Request $request, string $driver)
    {
        Log::channel('payments')->info('callback received', [
            'driver' => $driver,
            'payload' => Redact::secrets(json_encode($request->all(), JSON_UNESCAPED_UNICODE)),
        ]);

        if (! $this->payments->has($driver)) {
            return response()->json(['ok' => true]);
        }

        try {
            $provider = $this->payments->driver($driver);
            $transaction = $provider->handleCallback($request->all());

            if ($transaction) {

                $this->payments->settle($transaction, $provider->confirm($transaction));
            }
        } catch (\Throwable $e) {
            Log::channel('payments')->error('callback failed', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
        }

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

        return redirect()->route('order', $order->number);
    }
}
