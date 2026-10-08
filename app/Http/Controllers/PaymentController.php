<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    /**
     * A bank's callback.
     *
     * Answered with 200 whatever happens: a bank that receives an error retries
     * for hours, and our failure to parse is not its problem.
     */
    public function callback(Request $request, string $driver)
    {
        Log::channel('payments')->info('callback received', [
            'driver' => $driver,
            'payload' => $request->all(),
        ]);

        if (! $this->payments->has($driver)) {
            return response()->json(['ok' => true]);
        }

        try {
            $provider = $this->payments->driver($driver);
            $transaction = $provider->handleCallback($request->all());

            if ($transaction) {
                // the callback is a nudge, never proof: we ask the bank ourselves
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

    /**
     * Where the bank sends the customer back to.
     *
     * The three outcomes differ only in what we say: the money is confirmed by
     * payments:check, not by whichever URL the browser happened to land on.
     */
    public function return(Request $request, string $number)
    {
        $order = Order::where('number', $number)->firstOrFail();
        $status = $request->query('status', 'success');

        // a guest must still be able to see the page they just came back to
        session()->push('placed_orders', $order->id);

        if ($status !== 'success') {
            session()->flash('payment_failed', $status);
        }

        return redirect()->route('order', $order->number);
    }
}
