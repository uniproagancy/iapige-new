<?php

namespace App\Services\Orders;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Facebook\Pixel;
use App\Services\Payments\PaymentManager;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuickOrder
{
    public function quote(int $productId, int $quantity = 1, bool $lock = false): array
    {
        $this->validateQuantity($quantity);
        $product = Product::active()->with('brand')->withTranslation()
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($productId);

        if (! $product) {
            throw ValidationException::withMessages(['productId' => __('checkout.item_gone')]);
        }

        $price = round($product->sellingPrice(), 2);

        return [
            'id' => $product->id, 'sku' => $product->sku,
            'title' => product_title($product->brand?->name, $product->name),
            'unitPrice' => $price, 'quantity' => $quantity,
            'total' => round($price * $quantity, 2),
            'monthly' => $product->monthlyPrice(),
            'available' => $product->isSellable() && $product->stock >= $quantity && $price > 0,
        ];
    }

    public function groups(float $total): array
    {
        $groups = collect(['card' => collect(), 'installment' => collect(), 'cash' => collect(), 'transfer' => collect()]);

        foreach (PaymentMethod::active()->withTranslation()->get() as $method) {
            $category = $this->category($method);

            if ($category && $this->runnable($method) && $method->fitsTotal($total)) {
                $groups[$category]->push($method);
            }
        }

        $rank = ['bog_installment' => 0, 'bog_split' => 1, 'tbc_installment' => 2,
            'credo_installment' => 3, 'credo_split' => 4];
        $groups['installment'] = $groups['installment']->sortBy(fn ($method) => $rank[$method->code] ?? 99)->values();

        return $groups->all();
    }

    public function category(PaymentMethod $method): ?string
    {
        return match (true) {
            $method->code === 'cash' && ! $method->is_online => 'cash',
            $method->code === 'transfer' && ! $method->is_online => 'transfer',
            $method->driver === 'bog-card' => 'card',
            in_array($method->driver, ['bog-installment', 'tbc-installment', 'credo-installment', 'credo-split'], true) => 'installment',
            default => null,
        };
    }

    public function runnable(PaymentMethod $method): bool
    {
        return ! $method->is_online || ($method->driver && app(PaymentManager::class)->has($method->driver));
    }

    public function method(string $code, string $category, float $total): ?PaymentMethod
    {
        if ($code === '' && $category === '') {
            return null;
        }

        $method = PaymentMethod::active()->where('code', $code)->first();

        if (! $method || $this->category($method) !== $category || ! $this->runnable($method) || ! $method->fitsTotal($total)) {
            throw ValidationException::withMessages(['paymentMethod' => __('checkout.payment_unavailable')]);
        }

        return $method;
    }

    public function capture(array $input, array $context): CallbackRequest
    {
        $quantity = (int) ($input['quantity'] ?? 1);
        $this->validateQuantity($quantity);
        $phone = Phone::dial($input['phone'] ?? null);

        if (! $phone || ! preg_match('/^\+9955\d{8}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => __('quick_order.phone_invalid')]);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => __('validation.between.string', ['attribute' => __('checkout.name'), 'min' => 2, 'max' => 80])]);
        }

        $canonical = [
            'name' => $name, 'phone' => $phone, 'product_id' => $input['productId'] ?? null,
            'quantity' => $quantity, 'payment_category' => $input['paymentCategory'] ?? '',
            'payment_method' => $input['paymentMethod'] ?? '', 'comment' => trim((string) ($input['comment'] ?? '')),
        ];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = (string) $input['submissionKey'];
        $owner = (string) $context['owner'];
        if (! Str::isUuid($key) || ! preg_match('/^[a-f0-9]{64}$/', $owner)) {
            throw ValidationException::withMessages(['phone' => __('quick_order.changed_request')]);
        }

        $existing = CallbackRequest::where('submission_key', $key)->first();
        if ($existing) {
            return $this->sameSubmission($existing, $owner, $hash);
        }

        try {
            return DB::transaction(function () use ($context, $canonical, $hash, $key, $owner, $quantity) {
                $quote = $canonical['product_id'] ? $this->quote((int) $canonical['product_id'], $quantity, lock: true) : null;
                if ($quote && ! $quote['available']) {
                    throw ValidationException::withMessages(['productId' => __('checkout.item_gone')]);
                }
                $this->method($canonical['payment_method'], $canonical['payment_category'], $quote['total'] ?? 0);

                return CallbackRequest::create([
                    'submission_key' => $key, 'submission_owner' => $owner, 'request_hash' => $hash,
                    'user_id' => $context['user_id'] ?? null, 'product_id' => $canonical['product_id'],
                    'name' => $canonical['name'], 'phone' => $canonical['phone'],
                    'comment' => $canonical['comment'] ?: null, 'status' => CallbackRequest::NEW,
                    'page' => isset($context['source_url']) ? mb_substr($context['source_url'], 0, 255) : null,
                    'source_url' => isset($context['source_url']) ? mb_substr($context['source_url'], 0, 500) : null,
                    'product_name' => isset($quote['title']) ? mb_substr($quote['title'], 0, 255) : null,
                    'product_sku' => isset($quote['sku']) ? mb_substr((string) $quote['sku'], 0, 191) : null,
                    'quantity' => $quote ? $canonical['quantity'] : null,
                    'unit_price' => $quote['unitPrice'] ?? null, 'total' => $quote['total'] ?? null,
                    'currency' => $quote ? 'GEL' : null,
                    'payment_category' => $canonical['payment_category'] ?: null,
                    'payment_method' => $canonical['payment_method'] ?: null,
                    'lead_event_id' => Pixel::eventId('ld'),
                    'external_id' => isset($context['user_id']) ? (string) $context['user_id'] : 'lead:'.$key,
                    'fbp' => $context['fbp'] ?? null, 'fbc' => $context['fbc'] ?? null,
                    'ip' => $context['client_ip_address'] ?? null, 'user_agent' => $context['client_user_agent'] ?? null,
                    'consented' => (bool) ($context['consented'] ?? false),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = CallbackRequest::where('submission_key', $key)->first();
            if (! $existing) {
                throw $e;
            }

            return $this->sameSubmission($existing, $owner, $hash);
        }
    }

    protected function sameSubmission(CallbackRequest $lead, string $owner, string $hash): CallbackRequest
    {
        abort_unless(hash_equals((string) $lead->submission_owner, $owner), 403);
        if (! hash_equals((string) $lead->request_hash, $hash)) {
            throw ValidationException::withMessages(['phone' => __('quick_order.changed_request')]);
        }

        return $lead;
    }

    public function orderForLead(int $id, ?int $handledBy = null): Order
    {
        return DB::transaction(function () use ($id, $handledBy) {
            $lead = CallbackRequest::lockForUpdate()->findOrFail($id);

            if ($lead->order_id) {
                $order = Order::withTrashed()->findOrFail($lead->order_id);
                if ($order->trashed() || $order->status === Order::CANCELLED) {
                    throw ValidationException::withMessages(['paymentMethod' => __('quick_order.closed_order')]);
                }

                return $order;
            }

            if (! $lead->product_id) {
                throw ValidationException::withMessages(['productId' => __('admin.order_needs_an_item')]);
            }

            $quantity = $lead->quantity ?: 1;
            $this->validateQuantity($quantity);
            $product = Product::active()->lockForUpdate()->find($lead->product_id);
            if (! $product || ! $product->isSellable() || $product->stock < $quantity) {
                throw ValidationException::withMessages(['productId' => __('checkout.item_gone')]);
            }

            $priced = app(OrderLines::class)->price([['id' => $lead->product_id, 'qty' => $quantity]]);
            if ($lead->unit_price !== null && round((float) $priced['items'][0]['price'], 2) !== round((float) $lead->unit_price, 2)) {
                throw ValidationException::withMessages(['productId' => __('quick_order.price_changed')]);
            }

            $code = $lead->payment_method ?: 'cash';
            $category = $lead->payment_category ?: 'cash';
            $this->method($code, $category, $priced['subtotal']);
            $order = Order::create([
                'user_id' => $lead->user_id, 'status' => Order::NEW,
                'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->user?->email,
                'delivery' => 'courier', 'city' => null, 'address' => null,
                'comment' => $lead->comment, 'payment' => $code,
                'payment_status' => 'pending', 'is_paid' => false,
                'subtotal' => $priced['subtotal'], 'shipping' => 0, 'total' => $priced['subtotal'],
            ]);
            app(OrderLines::class)->attach($order, $priced['items']);
            $order->events()->create(['user_id' => $handledBy, 'type' => 'status', 'to' => Order::NEW,
                'note' => __('quick_order.created_from_lead')]);
            $this->link($lead, $order, $handledBy);

            if ($lead->submission_key && $lead->payment_method) {
                $lead->update(['checkout_event_id' => $lead->checkout_event_id ?: Pixel::eventId('ic'),
                    'checkout_started_at' => $lead->checkout_started_at ?: now(),
                    'checkout_data' => $lead->checkout_data ?: [
                        'content_type' => 'product',
                        'content_ids' => array_map(fn ($item) => (string) $item['product_id'], $priced['items']),
                        'contents' => array_map(fn ($item) => [
                            'id' => (string) $item['product_id'], 'quantity' => (int) $item['qty'],
                            'item_price' => round((float) $item['price'], 2),
                        ], $priced['items']),
                        'num_items' => array_sum(array_column($priced['items'], 'qty')),
                        'value' => round((float) $order->total, 2), 'currency' => 'GEL', 'order_id' => $order->number,
                    ]]);
            }

            return $order;
        });
    }

    public function link(CallbackRequest $lead, Order $order, ?int $handledBy = null): void
    {
        if ($lead->order_id && $lead->order_id !== $order->id) {
            throw ValidationException::withMessages(['callbackId' => __('quick_order.already_linked')]);
        }

        OrderPixelData::firstOrCreate(['order_id' => $order->id], [
            'event_id' => Pixel::eventId('pur'), 'external_id' => $lead->external_id,
            'fbp' => $lead->fbp, 'fbc' => $lead->fbc, 'ip' => $lead->ip,
            'user_agent' => $lead->user_agent, 'source_url' => $lead->source_url,
            'consented' => (bool) $lead->consented, 'action_source' => 'website',
        ]);

        $lead->update(['order_id' => $order->id] + ($handledBy ? [
            'status' => CallbackRequest::CALLED, 'handled_by' => $handledBy, 'handled_at' => now(),
        ] : []));
    }

    protected function validateQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw ValidationException::withMessages(['quantity' => __('quick_order.quantity_invalid')]);
        }
    }
}
