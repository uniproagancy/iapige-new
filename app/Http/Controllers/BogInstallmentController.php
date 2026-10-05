<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The endpoint the bank's calculator widget posts to.
 *
 * The widget runs in the customer's browser and decides the term, so the
 * application can only be created once they have chosen — which is why this is
 * a separate request rather than part of placing the order.
 */
class BogInstallmentController extends Controller
{
    public function __construct(protected PaymentManager $payments)
    {
    }

    public function create(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'month'         => ['required', 'integer', 'min:1', 'max:48'],
            'amount'        => ['nullable', 'numeric'],
            'discount_code' => ['nullable', 'string', 'max:64'],
        ]);

        $order = Order::where('number', $number)->firstOrFail();

        // only whoever placed it may pay for it
        abort_unless($this->mayPay($order), 403);

        if ($order->is_paid) {
            return response()->json(['error' => __('checkout.already_paid')], 409);
        }

        try {
            $transaction = $this->payments->driver('bog-installment')->start($order, [
                'months' => (int) $data['month'],
                'type'   => $this->typeFor($order),
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->error('bog application failed', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => __('checkout.payment_failed')], 422);
        }

        // the widget wants the bank's own id and nothing else
        return response()->json([
            'orderId'     => $transaction->external_id,
            'redirectUrl' => $transaction->redirect_url,
        ]);
    }

    /** STANDARD or ZERO, as the chosen payment method says. */
    protected function typeFor(Order $order): string
    {
        return PaymentMethod::where('code', $order->payment)->value('installment_type')
            ?: BogInstallment::STANDARD;
    }

    protected function mayPay(Order $order): bool
    {
        if (auth()->check() && $order->user_id === auth()->id()) {
            return true;
        }

        return in_array($order->id, (array) session('placed_orders', []), true);
    }
}
