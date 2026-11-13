<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderDeliveryManager
{
    public const WEEKDAYS_SETTING = 'delivery.weekdays';

    public function isInstalled(): bool
    {
        return Schema::hasTable('order_deliveries') && Schema::hasTable('order_delivery_items');
    }

    public function eligible(Order $order): bool
    {
        return ! $order->trashed() && $order->status === Order::COMPLETED
            && ($order->is_paid || $this->cashOnDelivery($order))
            && $order->delivery === 'courier' && $order->items()->exists();
    }

    public function cashOnDelivery(Order $order): bool
    {
        return $order->payment === 'cash'
            && PaymentMethod::where('code', 'cash')->where('is_online', false)->exists();
    }

    public function weekdays(): array
    {
        $days = json_decode(Setting::stored()[self::WEEKDAYS_SETTING] ?? 'null', true);

        if (! is_array($days) || $days === [] || count($days) > 7) {
            return [1, 3, 5];
        }

        foreach ($days as $day) {
            if (! is_int($day) || $day < 0 || $day > 6) {
                return [1, 3, 5];
            }
        }

        return array_values(array_unique($days));
    }

    public function saveWeekdays(array $days, User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException;
        }

        $valid = Validator::make(['days' => $days], [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['required', 'integer', 'between:0,6', 'distinct'],
        ])->validate();
        $days = array_map('intval', $valid['days']);
        sort($days);
        Setting::put(self::WEEKDAYS_SETTING, json_encode($days, JSON_THROW_ON_ERROR));
    }

    public function nextDate(?string $from = null, bool $strictlyAfter = false): string
    {
        $date = $from === null ? CarbonImmutable::now('Asia/Tbilisi')->startOfDay() : $this->date($from);
        $days = $this->weekdays();

        $firstOffset = $strictlyAfter ? 1 : 0;
        for ($offset = $firstOffset; $offset < $firstOffset + 7; $offset++) {
            $candidate = $date->addDays($offset);

            if (in_array($candidate->dayOfWeek, $days, true)) {
                return $candidate->toDateString();
            }
        }

        return $date->toDateString();
    }

    public function sync(Order|int $source): ?OrderDelivery
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $id = $source instanceof Order ? $source->id : $source;

        return DB::transaction(function () use ($id) {
            $order = Order::withTrashed()->whereKey($id)->lockForUpdate()->first();

            if (! $order) {
                return null;
            }

            $delivery = OrderDelivery::where('order_id', $order->id)->lockForUpdate()->first();
            $eligible = $this->eligible($order);

            if (! $delivery && ! $eligible) {
                return null;
            }

            $created = ! $delivery;
            $delivery ??= new OrderDelivery([
                'order_id' => $order->id,
                // New orders join the next delivery run; the board may still display today's run.
                'delivery_date' => $this->nextDate(strictlyAfter: true),
                'courier_id' => $this->soleCourierId(),
                'status' => OrderDelivery::ACTIVE,
            ]);
            $wasEligible = (bool) $delivery->is_eligible;
            $wasStatus = $delivery->status;
            $delivery->is_eligible = $eligible;
            $actor = auth()->user();
            $actor = $actor instanceof User && $actor->isStaff() ? $actor : null;
            $this->paymentIndicators($delivery, $order, $actor);

            // A completed delivery describes what was actually handed over that day.
            if ($delivery->delivered_at === null) {
                $delivery->fill($this->snapshot($order));
            }

            if ($order->trashed() || $order->status === Order::CANCELLED) {
                $delivery->status = OrderDelivery::CANCELLED;
            }

            if ($delivery->isDirty()) {
                $delivery->save();
            }

            if ($delivery->delivered_at === null) {
                $this->syncItems($delivery, $order);
            }

            if ($created) {
                $this->event($order, 'delivery_created', $actor, null, OrderDelivery::ACTIVE);
                if ($delivery->courier_id !== null) {
                    $this->event($order, 'delivery_assigned', $actor, null, (string) $delivery->courier_id);
                }
            } elseif ($wasStatus !== $delivery->status && $delivery->status === OrderDelivery::CANCELLED) {
                $this->event($order, 'delivery_cancelled', $actor, $wasStatus, $delivery->status,
                    __('delivery.events.order_cancelled'));
            } elseif ($wasEligible !== $eligible) {
                $this->event($order, 'delivery_eligibility', $actor, $wasEligible ? 'ready' : 'held', $eligible ? 'ready' : 'held');
            }

            return $this->fresh($delivery);
        }, 3);
    }

    public function updateDelivery(int $id, array $attributes, array $itemOverrides, User $actor): OrderDelivery
    {
        $this->staff($actor);
        $attributes = $this->deliveryValues($attributes);

        return DB::transaction(function () use ($id, $attributes, $itemOverrides, $actor) {
            [$order, $delivery] = $this->lockPair($id);
            $this->editable($delivery);
            if (array_key_exists('payment_bank', $attributes)) {
                $attributes['payment_bank'] = trim((string) $attributes['payment_bank']) ?: null;
                if ($attributes['payment_bank'] !== null && $order->payment !== 'transfer') {
                    $this->invalid('payment_bank', 'bank_transfer_only');
                }
            }

            if (isset($attributes['delivery_date'])) {
                $this->allowedDate($attributes['delivery_date'], $delivery->delivery_date->toDateString());
            }

            if (! empty($attributes['courier_id'])) {
                $courier = User::whereKey($attributes['courier_id'])->lockForUpdate()->first();

                if (! $courier || $courier->role() !== User::COURIER) {
                    $this->invalid('courier_id', 'courier_invalid');
                }
            }

            // The order editor changes city, delivery zone and shipping together.
            $customer = array_intersect_key($attributes, array_flip(['name', 'phone', 'address']));
            if (array_key_exists('payment_bank', $attributes)) {
                $customer['payment_bank'] = $attributes['payment_bank'];
            }

            if ($customer !== []) {
                $order->fill($customer)->save();
            }

            $this->syncItems($delivery, $order);
            $this->updateItems($delivery, $itemOverrides, $order, $actor);
            $previousDate = $delivery->delivery_date->toDateString();
            $previousCourier = $delivery->courier_id;
            $this->paymentIndicators($delivery, $order, $actor);
            $previousBank = $delivery->payment_bank;
            $delivery->fill($this->snapshot($order));
            $delivery->fill(array_intersect_key($attributes, array_flip(['delivery_date', 'courier_id', 'notes', 'payment_bank'])));
            $delivery->is_eligible = $this->eligible($order);
            $delivery->save();

            if ($previousDate !== $delivery->delivery_date->toDateString()) {
                $this->event($order, 'delivery_postponed', $actor, $previousDate, $delivery->delivery_date->toDateString(),
                    __('delivery.events.date_edited'));
            }

            if ((int) $previousCourier !== (int) $delivery->courier_id) {
                $this->event($order, 'delivery_assigned', $actor, $previousCourier === null ? null : (string) $previousCourier,
                    $delivery->courier_id === null ? null : (string) $delivery->courier_id);
            }
            if ($previousBank !== $delivery->payment_bank) {
                $this->bankEvent($order, $previousBank, $delivery->payment_bank, $actor);
            }

            $this->event($order, 'delivery_updated', $actor);

            return $this->fresh($delivery);
        }, 3);
    }

    public function postpone(int $id, string $date, string $reason, User $actor): OrderDelivery
    {
        $this->staff($actor);
        $reason = $this->note($reason);

        return DB::transaction(function () use ($id, $date, $reason, $actor) {
            [$order, $delivery] = $this->lockPair($id);
            $this->editable($delivery);
            $this->allowedDate($date);
            $previous = $delivery->delivery_date->toDateString();

            if ($date <= $previous) {
                $this->invalid('delivery_date', 'date_later');
            }

            $delivery->update(['delivery_date' => $date]);
            $this->event($order, 'delivery_postponed', $actor, $previous, $date, $reason);

            return $this->fresh($delivery);
        }, 3);
    }

    public function addComment(int $id, string $note, User $actor): OrderDelivery
    {
        $this->staff($actor);
        $note = $this->note($note);

        return DB::transaction(function () use ($id, $note, $actor) {
            [$order, $delivery] = $this->lockPair($id);
            $this->event($order, 'delivery_comment', $actor, null, null, $note);

            return $this->fresh($delivery);
        }, 3);
    }

    public function markPickedUp(int $id, User $actor): OrderDelivery
    {
        return DB::transaction(function () use ($id, $actor) {
            [$order, $delivery] = $this->lockPair($id);
            $this->deliveryActor($delivery, $actor);
            $this->editable($delivery);
            if (! $this->eligible($order)) {
                $this->invalid('delivery', 'not_ready');
            }
            if ($delivery->isPickedUp()) {
                return $this->fresh($delivery);
            }
            $delivery->forceFill(['picked_up_at' => now(), 'picked_up_by' => $actor->id])->save();
            $this->event($order, 'delivery_picked_up', $actor);

            return $this->fresh($delivery);
        }, 3);
    }

    public function actionCorrectionVersion(OrderDelivery $delivery): string
    {
        return hash('sha256', json_encode([
            'delivery' => $delivery->only([
                'status', 'is_eligible', 'picked_up_at', 'picked_up_by', 'delivered_at', 'delivered_by',
                'cash_received_at', 'cash_received_by', 'cash_received_amount', 'updated_at',
            ]),
            // An undo and repeat action can share the same second and action fields.
            'event_id' => (int) OrderEvent::where('order_id', $delivery->order_id)
                ->where('type', 'like', 'delivery_%')->max('id'),
        ], JSON_THROW_ON_ERROR));
    }

    public function correctActions(int $id, string $target, string $reason, User $actor, string $expectedVersion): OrderDelivery
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException;
        }
        $reason = $this->note($reason);
        if (! in_array($target, ['not_picked_up', 'picked_up'], true)) {
            $this->invalid('delivery', 'correction_target');
        }

        return DB::transaction(function () use ($id, $target, $reason, $actor, $expectedVersion) {
            [$order, $delivery] = $this->lockPair($id);
            if ($order->trashed() || $order->status === Order::CANCELLED || ! $delivery->canCorrectActions()) {
                $this->invalid('delivery', 'correction_unavailable');
            }
            if (! hash_equals($this->actionCorrectionVersion($delivery), $expectedVersion)) {
                $this->invalid('delivery', 'correction_changed');
            }
            if ($target === 'picked_up'
                && ($delivery->status !== OrderDelivery::DELIVERED || ! $delivery->isPickedUp())) {
                $this->invalid('delivery', 'correction_target');
            }

            $previous = $delivery->status === OrderDelivery::DELIVERED ? 'delivered' : 'picked_up';
            $attributes = [
                'status' => OrderDelivery::ACTIVE,
                'is_eligible' => $this->eligible($order),
                'delivered_at' => null,
                'delivered_by' => null,
            ];
            if ($target === 'not_picked_up') {
                $attributes += ['picked_up_at' => null, 'picked_up_by' => null];
            }
            // A logistics correction does not reverse a recorded collection or bank payment.
            $delivery->forceFill($attributes)->save();
            $this->event($order, 'delivery_actions_corrected', $actor, $previous, $target, $reason);

            return $this->fresh($delivery);
        }, 3);
    }

    public function markDelivered(int $id, User $actor): OrderDelivery
    {
        return DB::transaction(function () use ($id, $actor) {
            [$order, $delivery] = $this->lockPair($id);

            $this->deliveryActor($delivery, $actor);

            if ($delivery->status === OrderDelivery::DELIVERED) {
                return $this->fresh($delivery);
            }

            $this->editable($delivery);
            $this->pickupRequired($delivery, $actor);

            if (! $this->eligible($order)) {
                $this->invalid('delivery', 'not_ready');
            }
            if ($this->cashOnDelivery($order) && ! $delivery->hasCashReceipt()) {
                $this->invalid('delivery', 'cash_receipt_required');
            }

            $this->paymentIndicators($delivery, $order, $actor);
            $delivery->fill($this->snapshot($order));
            $this->syncItems($delivery, $order);
            $delivery->forceFill([
                'status' => OrderDelivery::DELIVERED,
                'is_eligible' => true,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
            ])->save();
            $this->event($order, 'delivery_delivered', $actor, OrderDelivery::ACTIVE, OrderDelivery::DELIVERED);

            return $this->fresh($delivery);
        }, 3);
    }

    public function cashAmountCents(int $id, User $actor): int
    {
        return DB::transaction(function () use ($id, $actor) {
            [$order, $delivery] = $this->lockPair($id);
            $this->deliveryActor($delivery, $actor);
            $this->cashDeliveryReady($delivery, $order, $actor);

            return $this->cashQuoteCents($delivery, $order);
        }, 3);
    }

    public function markCashDelivered(int $id, User $actor, int $expectedAmountCents): OrderDelivery
    {
        return DB::transaction(function () use ($id, $actor, $expectedAmountCents) {
            [$order, $delivery] = $this->lockPair($id);
            $this->deliveryActor($delivery, $actor);
            if ($delivery->status === OrderDelivery::DELIVERED && $delivery->hasCashReceipt()) {
                return $this->fresh($delivery);
            }
            $this->cashDeliveryReady($delivery, $order, $actor);
            if ($expectedAmountCents < 0) {
                $this->invalid('delivery', 'cash_amount_invalid');
            }
            if ($this->cashQuoteCents($delivery, $order) !== $expectedAmountCents) {
                $this->invalid('delivery', 'cash_price_changed');
            }

            $previousStatus = $order->payment_status;
            $payment = ['payment_status' => 'paid'];
            if (! $order->is_paid) {
                $payment += ['is_paid' => true, 'paid_at' => now()];
            }
            $order->fill($payment);
            if ($order->isDirty()) {
                $order->save();
            }
            $delivery->forceFill(['cash_received_at' => now(), 'cash_received_by' => $actor->id,
                'cash_received_amount' => $order->total])->save();
            $note = __('delivery.events.cash_received', ['amount' => $order->total]);
            $this->event($order, 'payment', $actor, $previousStatus, 'paid', $note);
            $this->event($order, 'delivery_cash_received', $actor, null, 'paid', $note);
            if ($delivery->status === OrderDelivery::DELIVERED) {
                // A legacy handover is already a fact; late collection must not manufacture a second one.
                $this->paymentIndicators($delivery, $order, $actor);
                $delivery->save();

                $result = $this->fresh($delivery);
            } else {
                $result = $this->markDelivered($id, $actor);
            }

            return $result;
        }, 3);
    }

    public function cancel(int $id, string $reason, User $actor): OrderDelivery
    {
        $this->staff($actor);
        $reason = $this->note($reason);

        return DB::transaction(function () use ($id, $reason, $actor) {
            [$order, $delivery] = $this->lockPair($id);

            if ($delivery->status === OrderDelivery::CANCELLED) {
                return $this->fresh($delivery);
            }

            $this->editable($delivery);
            $previousStatus = $order->status;
            $order->update(['status' => Order::CANCELLED]);
            $order->events()->create([
                'user_id' => $actor->id, 'type' => 'status', 'from' => $previousStatus,
                'to' => Order::CANCELLED, 'note' => $reason,
            ]);
            $delivery->update(['status' => OrderDelivery::CANCELLED, 'is_eligible' => false]);
            $this->event($order, 'delivery_cancelled', $actor, OrderDelivery::ACTIVE, OrderDelivery::CANCELLED, $reason);

            return $this->fresh($delivery);
        }, 3);
    }

    protected function snapshot(Order $order): array
    {
        return [
            'name' => $order->name, 'phone' => $order->phone, 'city' => $order->city,
            'address' => $order->address, 'customer_comment' => $order->comment, 'payment' => $order->payment,
            'subtotal' => $order->subtotal, 'shipping' => $order->shipping, 'total' => $order->total,
            'is_paid' => $order->is_paid, 'paid_at' => $order->paid_at,
        ];
    }

    protected function soleCourierId(): ?int
    {
        $ids = User::withRole(User::COURIER)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    protected function paymentIndicators(OrderDelivery $delivery, Order $order, ?User $actor = null): void
    {
        if ($delivery->payment !== $order->payment && $delivery->payment_bank !== null) {
            $this->bankEvent($order, $delivery->payment_bank, null, $actor);
            $delivery->payment_bank = null;
        }
        if ($order->payment === 'transfer' && $order->payment_bank !== null
            && $delivery->payment_bank !== $order->payment_bank) {
            if ($delivery->exists) {
                $this->bankEvent($order, $delivery->payment_bank, $order->payment_bank, $actor);
            }
            $delivery->payment_bank = $order->payment_bank;
        }
        // Settlement may arrive after handover; only its indicators follow the canonical order.
        $delivery->fill(['payment' => $order->payment, 'is_paid' => $order->is_paid, 'paid_at' => $order->paid_at]);
    }

    protected function bankEvent(Order $order, ?string $from, ?string $to, ?User $actor): void
    {
        $this->event($order, 'delivery_bank_updated', $actor, null, null,
            __('delivery.events.bank_updated', ['from' => $from ?: '—', 'to' => $to ?: '—']));
    }

    protected function deliveryActor(OrderDelivery $delivery, User $actor): void
    {
        if (! $actor->isStaff() && ($actor->role() !== User::COURIER || (int) $delivery->courier_id !== (int) $actor->id)) {
            throw new AuthorizationException;
        }
    }

    protected function cashReady(Order $order): void
    {
        if (! $this->cashOnDelivery($order)) {
            $this->invalid('delivery', 'cash_not_available');
        }
        if (! $this->eligible($order)) {
            $this->invalid('delivery', 'not_ready');
        }
    }

    protected function cashDeliveryReady(OrderDelivery $delivery, Order $order, User $actor): void
    {
        if ($delivery->status !== OrderDelivery::DELIVERED) {
            $this->editable($delivery);
            $this->pickupRequired($delivery, $actor);
        }
        $this->cashReady($order);
        if ($delivery->hasCashReceipt()) {
            $this->invalid('delivery', 'cash_already_received');
        }
    }

    protected function cashQuoteCents(OrderDelivery $delivery, Order $order): int
    {
        $actual = $this->amountCents($order);
        if ($delivery->status === OrderDelivery::DELIVERED && $this->decimalCents((string) $delivery->total) !== $actual) {
            $this->invalid('delivery', 'cash_price_changed');
        }

        return $actual;
    }

    protected function pickupRequired(OrderDelivery $delivery, User $actor): void
    {
        if (! $actor->isStaff() && ! $delivery->isPickedUp()) {
            $this->invalid('delivery', 'pickup_required');
        }
    }

    protected function amountCents(Order $order): int
    {
        return $this->decimalCents((string) $order->total);
    }

    protected function decimalCents(string $amount): int
    {
        if (! preg_match('/\A([0-9]+)\.([0-9]{2})\z/D', $amount, $matches)) {
            $this->invalid('delivery', 'cash_amount_invalid');
        }

        return (int) $matches[1] * 100 + (int) $matches[2];
    }

    protected function syncItems(OrderDelivery $delivery, Order $order): void
    {
        $existing = $delivery->allItems()->lockForUpdate()->get();
        $used = [];
        $items = $order->items()->with('product.offers.supplier')->orderBy('id')->lockForUpdate()->get();

        foreach ($items as $item) {
            $snapshot = $existing->first(fn (OrderDeliveryItem $candidate) => ! in_array($candidate->id, $used, true)
                && (int) $candidate->order_item_id === (int) $item->id);

            // Order editing recreates item IDs. Match the retained line before copying source overrides.
            $snapshot ??= $existing->first(fn (OrderDeliveryItem $candidate) => $candidate->is_active
                && ! in_array($candidate->id, $used, true) && $this->itemKey($candidate) === $this->itemKey($item));
            $snapshot ??= new OrderDeliveryItem([
                'order_delivery_id' => $delivery->id,
                ...$this->defaultSupplier($item),
            ]);
            $snapshot->fill([
                'order_item_id' => $item->id, 'product_id' => $item->product_id,
                'sku' => $item->sku, 'name' => $item->name, 'qty' => $item->qty,
                'price' => $item->price, 'sum' => $item->sum, 'is_active' => true,
            ]);

            if ($snapshot->isDirty()) {
                $snapshot->save();
            }

            $used[] = $snapshot->id;
        }

        foreach ($existing as $snapshot) {
            if (! in_array($snapshot->id, $used, true) && $snapshot->is_active) {
                $snapshot->update(['is_active' => false, 'order_item_id' => null]);
            }
        }
    }

    protected function itemKey(OrderItem|OrderDeliveryItem $item): string
    {
        return json_encode([$item->product_id === null ? null : (int) $item->product_id, (string) $item->sku,
            $item->product_id ? null : (string) $item->name], JSON_THROW_ON_ERROR);
    }

    protected function defaultSupplier(OrderItem $item): array
    {
        $offers = $item->product?->offers->filter(fn ($offer) => $offer->supplier?->is_active && $offer->stock > 0);

        if ($offers && $offers->count() > 1) {
            $cost = $item->product->cost_price;
            $offers = $offers->filter(fn ($offer) => round((float) $offer->cost_price * 100) === round((float) $cost * 100));
        }

        $offer = $offers?->count() === 1 ? $offers->first() : null;

        return [
            'supplier_id' => $offer?->supplier_id,
            'supplier_name' => $offer?->supplier?->name,
            'supplier_sku' => $offer?->external_id,
        ];
    }

    protected function updateItems(OrderDelivery $delivery, array $overrides, Order $order, User $actor): void
    {
        $items = $delivery->items()->lockForUpdate()->get()->keyBy('id');

        foreach ($overrides as $id => $values) {
            if (! ctype_digit((string) $id) || ! is_array($values) || ! $items->has((int) $id)) {
                $this->invalid('items', 'item_invalid');
            }

            $item = $items[(int) $id];
            $valid = Validator::make($values, [
                'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
                'supplier_name' => ['nullable', 'string', 'max:255'],
                'supplier_sku' => ['nullable', 'string', 'max:191'],
                'warehouse' => ['nullable', 'string', 'max:255'],
                'pickup_address' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $new = array_replace($item->only(['supplier_id', 'supplier_name', 'supplier_sku', 'warehouse', 'pickup_address']), $valid);
            $new['supplier_id'] = empty($new['supplier_id']) ? null : (int) $new['supplier_id'];

            foreach (['supplier_name', 'supplier_sku', 'warehouse', 'pickup_address'] as $field) {
                $new[$field] = trim((string) ($new[$field] ?? '')) ?: null;
            }

            if ($new['supplier_id']) {
                $new['supplier_name'] = Supplier::findOrFail($new['supplier_id'])->name;
            }

            $supplierChanged = (int) $new['supplier_id'] !== (int) $item->supplier_id || $new['supplier_name'] !== $item->supplier_name;

            if ($supplierChanged) {
                foreach (['supplier_sku', 'warehouse', 'pickup_address'] as $field) {
                    if ($new[$field] === $item->{$field}) {
                        $new[$field] = null;
                    }
                }
            }

            if ($new['warehouse'] !== $item->warehouse && $new['pickup_address'] === $item->pickup_address) {
                $new['pickup_address'] = null;
            }

            $beforeWarehouse = $item->warehouse;
            $beforeSupplier = $item->supplier_name;
            $item->fill($new);

            if ($item->isDirty()) {
                $item->is_manual = true;
                $item->save();
                $this->event($order, 'delivery_source_updated', $actor, null, null,
                    __('delivery.events.source_updated', ['item' => $item->name,
                        'from' => $beforeSupplier ?: '—', 'to' => $item->supplier_name ?: '—',
                        'warehouse_from' => $beforeWarehouse ?: '—', 'warehouse_to' => $item->warehouse ?: '—']));
            }
        }
    }

    protected function deliveryValues(array $attributes): array
    {
        if (array_key_exists('courier_id', $attributes) && $attributes['courier_id'] === '') {
            $attributes['courier_id'] = null;
        }

        return Validator::make($attributes, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'delivery_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'courier_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payment_bank' => ['sometimes', 'nullable', 'string', 'max:120'],
        ])->validate();
    }

    protected function lockPair(int $id): array
    {
        $reference = OrderDelivery::findOrFail($id);
        $order = Order::withTrashed()->whereKey($reference->order_id)->lockForUpdate()->firstOrFail();
        $delivery = OrderDelivery::whereKey($id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();

        return [$order, $delivery];
    }

    protected function fresh(OrderDelivery $delivery): OrderDelivery
    {
        return $delivery->fresh(['order', 'items', 'courier']);
    }

    protected function staff(User $actor): void
    {
        if (! $actor->isStaff()) {
            throw new AuthorizationException;
        }
    }

    protected function editable(OrderDelivery $delivery): void
    {
        if (! $delivery->isEditable()) {
            $this->invalid('delivery', 'closed');
        }
    }

    protected function date(string $value): CarbonImmutable
    {
        Validator::make(['delivery_date' => $value], ['delivery_date' => ['required', 'date_format:Y-m-d']])->validate();

        return CarbonImmutable::parse($value, 'Asia/Tbilisi')->startOfDay();
    }

    protected function allowedDate(string $value, ?string $unchanged = null): void
    {
        $date = $this->date($value);

        if ($value !== $unchanged && ! in_array($date->dayOfWeek, $this->weekdays(), true)) {
            $this->invalid('delivery_date', 'schedule_day');
        }
    }

    protected function note(string $value): string
    {
        $value = trim($value);
        Validator::make(['note' => $value], ['note' => ['required', 'string', 'max:2000']])->validate();

        return $value;
    }

    protected function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('delivery.errors.'.$key)]);
    }

    protected function event(Order $order, string $type, ?User $actor = null, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        $order->events()->create([
            'user_id' => $actor?->id, 'type' => $type, 'from' => $from, 'to' => $to, 'note' => $note,
        ]);
    }
}
