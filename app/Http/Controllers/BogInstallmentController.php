<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BogInstallmentController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    public function create(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:48'],
            'amount' => ['nullable', 'numeric'],
            'discount_code' => ['nullable', 'string', 'max:64'],
        ]);

        $order = Order::where('number', $number)->firstOrFail();

        abort_unless($this->mayPay($order), 403);

        if ($order->is_paid) {
            return response()->json(['error' => __('checkout.already_paid')], 409);
        }

        try {
            $transaction = $this->payments->startTransaction($order, 'bog-installment', [
                'months' => (int) $data['month'],
                'type' => $this->typeFor($order),
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->error('bog application failed', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => __('checkout.payment_failed')], 422);
        }

        return response()->json([
            'orderId' => $transaction->external_id,
            'redirectUrl' => $transaction->redirect_url,
        ]);
    }

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
