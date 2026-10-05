<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bank of Georgia online instalments.
 *
 * Two products share one endpoint:
 *   STANDARD — the customer pays the bank's interest, and the shop adds a
 *              handling fee on top of the price it would otherwise charge;
 *   ZERO     — the shop carries the cost, so the amount is the cart total.
 *
 * The bank's redirect is not proof of anything: a customer can close the tab
 * at the bank's own page. The verdict is only ever read back from the bank,
 * which is what payments:check does.
 */
class BogInstallment implements PaymentDriver
{
    /** Terms the shop offers, in months. */
    public const MONTHS = [3, 6, 12, 18, 24];

    public const STANDARD = 'STANDARD';
    public const ZERO = 'ZERO';

    public function start(Order $order, array $options = []): PaymentTransaction
    {
        $months = (int) ($options['months'] ?? 12);
        $type = $options['type'] ?? self::STANDARD;

        if ($months < 1 || $months > 48) {
            throw new RuntimeException("Implausible instalment term: {$months} months.");
        }

        // the handling fee applies to the interest-bearing product only
        $fee = $type === self::STANDARD ? (float) config('bog.installment.handling_fee', 0.05) : 0.0;
        $amount = round((float) $order->total * (1 + $fee), 2);

        $payload = $this->payload($order, $months, $type, $fee, $amount);

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->post(config('bog.installment.order_url'), $payload);

        $data = $response->json() ?? [];

        if (! $response->successful() || ($data['status'] ?? null) !== 'CREATED') {
            Log::channel('payments')->error('bog refused the application', [
                'order'  => $order->number,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException(__('checkout.payment_failed'));
        }

        return PaymentTransaction::create([
            'order_id'     => $order->id,
            'driver'       => 'bog-installment',
            'external_id'  => $data['order_id'] ?? null,
            'amount'       => $amount,
            'months'       => $months,
            'status'       => PaymentTransaction::PENDING,
            'request'      => $payload,
            'response'     => $data,
            'redirect_url' => $this->redirectFrom($data),
        ]);
    }

    /**
     * Asks the bank what became of an application.
     *
     * Only an explicit success counts: everything else — "processing",
     * "in_progress", an empty answer — leaves the transaction pending so the
     * next run asks again.
     */
    public function confirm(PaymentTransaction $transaction): bool
    {
        if (! $transaction->external_id) {
            return false;
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)
            ->get(rtrim((string) config('bog.installment.status_url'), '/').'/'.$transaction->external_id);

        if (! $response->successful()) {
            Log::channel('payments')->warning('bog status unavailable', [
                'transaction' => $transaction->id,
                'status'      => $response->status(),
            ]);

            return false;
        }

        $data = $response->json() ?? [];
        $status = strtolower((string) ($data['installment_status'] ?? ''));

        $transaction->update([
            'response'   => array_merge((array) $transaction->response, ['last_check' => $data]),
            'checked_at' => now(),
        ]);

        // the bank echoes our own order number back; a mismatch means we asked
        // about somebody else's application and must not act on the answer
        $echoed = (string) ($data['shop_order_id'] ?? '');

        if ($echoed !== '' && $transaction->order && $echoed !== (string) $transaction->order->number) {
            Log::channel('payments')->error('bog returned a foreign order', [
                'transaction' => $transaction->id,
                'expected'    => $transaction->order->number,
                'received'    => $echoed,
            ]);

            return false;
        }

        if (in_array($status, ['rejected', 'failed', 'cancelled'], true)) {
            $transaction->update(['status' => PaymentTransaction::FAILED]);

            return false;
        }

        return $status === 'success';
    }

    /** The bank posts here when it sends a callback at all. */
    public function handleCallback(array $payload): ?PaymentTransaction
    {
        $externalId = $payload['order_id'] ?? $payload['body']['order_id'] ?? null;

        if (! $externalId) {
            return null;
        }

        $transaction = PaymentTransaction::where('driver', 'bog-installment')
            ->where('external_id', $externalId)
            ->first();

        $transaction?->update([
            'response' => array_merge((array) $transaction->response, ['callback' => $payload]),
        ]);

        return $transaction;
    }

    /* ------------------------------------------------------------------ plumbing */

    /** Cached five minutes short of its life, so none travels expiring. */
    protected function token(): string
    {
        $cached = Cache::get('bog:installment:token');

        if ($cached) {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            ->withHeaders([
                'Authorization' => 'Basic '.base64_encode(
                    config('bog.installment.public_key').':'.config('bog.installment.secret_key')
                ),
            ])
            ->post(config('bog.token_url'), ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Could not authenticate with the bank.');
        }

        $token = (string) $response->json('access_token');

        // the bank states its own lifetime; a minute is kept back for the trip
        $ttl = max(30, (int) $response->json('expires_in', 300) - 60);

        Cache::put('bog:installment:token', $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * The redirect sits in a links array whose order the bank does not promise,
     * so it is found by relation rather than by position.
     */
    protected function redirectFrom(array $data): ?string
    {
        foreach ($data['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve' || ($link['method'] ?? '') === 'REDIRECT') {
                return $link['href'] ?? null;
            }
        }

        return $data['links'][1]['href'] ?? $data['links'][0]['href'] ?? null;
    }

    /** @return array<string, mixed> */
    protected function payload(Order $order, int $months, string $type, float $fee, float $amount): array
    {
        $items = $order->items->map(function ($item) use ($fee) {
            $product = $item->product;

            return array_filter([
                'total_item_amount'    => round(((float) $item->price * (1 + $fee)) * $item->qty, 2),
                'item_description'     => mb_substr($item->name, 0, 100),
                'total_item_qty'       => (int) $item->qty,
                'item_vendor_code'     => $item->sku ?: (string) $item->product_id,
                'product_image_url'    => $product?->images->first()?->url(),
                'item_site_detail_url' => $product?->slug ? route('product', $product->slug) : null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        return [
            'intent'               => 'LOAN',
            'installment_month'    => $months,
            'installment_type'     => $type,
            // the order number, not the id: it is what the bank echoes back
            'shop_order_id'        => (string) $order->number,
            'success_redirect_url' => route('payment.return', ['number' => $order->number, 'status' => 'success']),
            'fail_redirect_url'    => route('payment.return', ['number' => $order->number, 'status' => 'fail']),
            'reject_redirect_url'  => route('payment.return', ['number' => $order->number, 'status' => 'reject']),
            'validate_items'       => true,
            'locale'               => 'ka',
            'purchase_units'       => [
                ['amount' => ['currency_code' => 'GEL', 'value' => $amount]],
            ],
            'cart_items'           => $items,
        ];
    }
}
