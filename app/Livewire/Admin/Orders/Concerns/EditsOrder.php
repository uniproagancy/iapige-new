<?php

namespace App\Livewire\Admin\Orders\Concerns;

use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\Product;
use App\Services\Delivery\DeliveryCalculator;
use App\Services\Facebook\Pixel;
use App\Services\Orders\OrderLines;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;

/*
 | An order is read and corrected in the same place, so the page that shows it
 | carries its fields too. This lives apart from that page only because there
 | is a lot of it, not because it is used anywhere else.
 */
trait EditsOrder
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $delivery = 'courier';

    public ?int $cityId = null;

    public string $address = '';

    public string $comment = '';

    public string $payment = '';

    public ?string $shippingOverride = null;

    public array $qty = [];

    public array $agreed = [];

    public string $productSearch = '';

    /* The fields start as whatever the order already says, and are read back
       from it after every write so a correction never leaves a stale figure. */
    public function fillFromOrder(): void
    {
        $order = $this->order;

        $this->name = (string) $order->name;
        $this->phone = (string) $order->phone;
        $this->email = (string) $order->email;

        $this->delivery = $order->delivery ?: 'courier';
        $this->cityId = $order->delivery_city_id;
        $this->address = (string) $order->address;
        $this->comment = (string) $order->comment;
        $this->payment = (string) $order->payment;

        $this->qty = [];
        $this->agreed = [];

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $this->qty[$item->product_id] = (int) $item->qty;
            $this->agreed[$item->product_id] = (float) $item->price;
        }

        $this->shippingOverride = (string) $order->shipping;
    }

    public function editable(): bool
    {
        return $this->order->status !== Order::CANCELLED;
    }

    public function found()
    {
        $term = trim($this->productSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        /* The same search the shop itself uses: a name, a code, a brand, and a
           brand typed in Georgian letters. What stood here looked for a name
           that was never searched -- whereTranslation takes a column and a
           value, not an operator -- so only a product code ever matched. */
        return Catalog::matching(Catalog::productQuery(), $term)
            ->orderByDesc('sales_count')
            ->limit(12)
            ->get();
    }

    public function add(int $id): void
    {
        $sellable = Product::whereKey($id)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_preorder', false)
            ->exists();

        if (! $sellable) {
            $this->dispatch('toast', type: 'error', message: __('admin.product_not_sellable'));

            return;
        }

        $this->qty[$id] = ($this->qty[$id] ?? 0) + 1;
        $this->productSearch = '';
    }

    public function increment(int $id): void
    {
        if (isset($this->qty[$id])) {
            $this->qty[$id] = min(99, $this->qty[$id] + 1);
        }
    }

    public function decrement(int $id): void
    {
        if (! isset($this->qty[$id])) {
            return;
        }

        $this->qty[$id] > 1 ? $this->qty[$id]-- : $this->remove($id);
    }

    public function remove(int $id): void
    {
        unset($this->qty[$id]);
    }

    public function lines(): array
    {
        if (! $this->qty) {
            return [];
        }

        $products = Product::whereIn('id', array_keys($this->qty))
            ->with('brand')
            ->withTranslation()
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($this->qty as $id => $qty) {
            $product = $products[$id] ?? null;

            if (! $product) {
                continue;
            }

            $price = $this->agreed[$id] ?? $product->sellingPrice();

            $lines[] = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => product_title($product->brand?->name, $product->name),
                'price' => $price,
                'qty' => (int) $qty,
                'sum' => $price * (int) $qty,
                'today' => $product->sellingPrice(),
                'agreed' => isset($this->agreed[$id]),
                'stock' => (int) $product->stock,
            ];
        }

        return $lines;
    }

    public function subtotal(): float
    {
        return (float) array_sum(array_column($this->lines(), 'sum'));
    }

    public function shipping(): float
    {
        if ($this->shippingOverride !== null && $this->shippingOverride !== '') {
            return round((float) $this->shippingOverride, 2);
        }

        if ($this->delivery === 'pickup') {
            return 0.0;
        }

        $city = $this->cityId ? DeliveryCity::active()->find($this->cityId) : null;

        if (! $city) {
            return 0.0;
        }

        $items = array_map(
            fn (array $line) => ['product_id' => $line['id'], 'qty' => $line['qty']],
            $this->lines(),
        );

        return app(DeliveryCalculator::class)->fee($city, app(OrderLines::class)->measured($items));
    }

    public function total(): float
    {
        return $this->subtotal() + $this->shipping();
    }

    public function recalculateShipping(): void
    {
        $this->shippingOverride = null;
    }

    public function save(): void
    {
        if (! $this->editable()) {
            $this->dispatch('toast', type: 'error', message: __('admin.order_closed_for_edit'));

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'delivery' => ['required', 'in:courier,pickup'],
            'cityId' => ['nullable', 'exists:delivery_cities,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'payment' => ['required', 'string', 'exists:payment_methods,code'],
            'shippingOverride' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $this->lines()) {
            $this->addError('qty', __('admin.order_needs_an_item'));

            return;
        }

        $was = (float) $this->order->total;

        DB::transaction(function () {
            $order = Order::whereKey($this->order->id)->lockForUpdate()->firstOrFail();

            $this->moveSales($order);

            $lines = $this->lines();
            $shipping = $this->shipping();
            $subtotal = (float) array_sum(array_column($lines, 'sum'));

            $order->items()->delete();

            $order->items()->createMany(array_map(fn (array $line) => [
                'product_id' => $line['id'],
                'sku' => $line['sku'],
                'name' => $line['name'],
                'price' => $line['price'],
                'qty' => $line['qty'],
                'sum' => $line['sum'],
            ], $lines));

            /* A quick order carries a telephone number and nothing else, so
               there is no city to write until somebody rings back and asks.
               Without one the courier fee stays whatever it was. */
            $city = $this->delivery === 'courier' && $this->cityId
                ? DeliveryCity::withTranslation()->find($this->cityId)
                : null;

            $order->update([
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email ?: null,
                'delivery' => $this->delivery,
                'delivery_city_id' => $city?->id,
                'city' => $city?->name,
                'address' => $this->delivery === 'courier' ? $this->address : null,
                'comment' => $this->comment ?: null,
                'payment' => $this->payment,
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'total' => $subtotal + $shipping,
            ]);

            $this->order = $order;
        });

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'note',
            'note' => __('admin.order_edited', [
                'name' => auth()->user()?->name ?? '—',
                'was' => money($was),
                'now' => money($this->order->total),
            ]),
        ]);

        /* What Meta is told about must be the order as corrected, so the
           frozen copy goes while nothing has been sent yet. */
        app(Pixel::class)->refreshAfterEdit($this->order);

        $this->order = $this->order->fresh(['items.product.images', 'events.user', 'user']);
        $this->fillFromOrder();

        $this->dispatch('toast', message: __('admin.saved'));
    }

    protected function moveSales(Order $order): void
    {
        $before = $order->items()->pluck('qty', 'product_id');

        foreach ($this->qty as $id => $qty) {
            $delta = (int) $qty - (int) ($before[$id] ?? 0);

            if ($delta > 0) {
                Product::whereKey($id)->increment('sales_count', $delta);
            } elseif ($delta < 0) {
                Product::whereKey($id)->decrement('sales_count', -$delta);
            }
        }

        foreach ($before as $id => $qty) {
            if (! isset($this->qty[$id])) {
                Product::whereKey($id)->decrement('sales_count', (int) $qty);
            }
        }
    }
}
