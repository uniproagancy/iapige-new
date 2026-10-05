<?php

namespace App\Livewire\Pages;

use App\Models\Cart as CartModel;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\UserAddress;
use App\Services\Cart;
use App\Services\Payments\Drivers\BogInstallment;
use App\Services\Payments\PaymentManager;
use App\Support\Catalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

class Checkout extends Component
{
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|string|min:9|max:32')]
    public string $phone = '';

    #[Validate('nullable|email|max:120')]
    public string $email = '';

    #[Validate('required|exists:delivery_cities,id')]
    public ?int $cityId = null;

    #[Validate('required|string|min:5|max:160')]
    public string $address = '';

    public string $comment = '';

    /**
     * A method that exists — the field is a code posted by the browser, so on
     * its own it would let any string end up in orders.payment.
     */
    #[Validate('required|string|exists:payment_methods,code')]
    public string $payment = '';

    /** a signed-in customer keeps the address for next time */
    public bool $saveAddress = true;
    public ?int $addressId = null;

    /** set once the order exists, so the page can speak about it */
    public ?string $placedNumber = null;

    #[Url(as: 'product', except: null)]
    public ?int $productId = null;

    #[Url(as: 'qty', except: 1)]
    public int $qty = 1;

    public bool $buyNow = false;

    public function mount(): void
    {
        $this->qty = max(1, min(99, $this->qty));
        $this->buyNow = $this->productId !== null;

        // a pre-order cannot be bought, so buy-now must not smuggle one through
        if ($this->buyNow && ! Product::active()->whereKey($this->productId)->where('is_preorder', false)->exists()) {
            $this->buyNow = false;
            $this->productId = null;
        }

        if (! $this->buyNow && app(Cart::class)->count() === 0) {
            $this->redirect(route('catalog'), navigate: false);

            return;
        }

        if ($user = Auth::user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) ($user->phone ?? '');
            $this->email = (string) $user->email;

            $this->fillFromDefaultAddress();
        }

        $this->cityId ??= DeliveryCity::active()->value('id');
        $this->payment = $this->payment ?: (string) (PaymentMethod::active()->value('code') ?? 'cash');
    }

    /** The address the customer used last time, so the form opens filled in. */
    protected function fillFromDefaultAddress(): void
    {
        $address = UserAddress::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->first();

        if (! $address) {
            return;
        }

        $this->addressId = $address->id;
        $this->cityId = $address->city_id;
        $this->address = (string) $address->address;
        $this->comment = (string) $address->note;

        if ($address->name) {
            $this->name = $address->name;
        }

        if ($address->phone) {
            $this->phone = $address->phone;
        }
    }

    public function useAddress(int $id): void
    {
        $address = UserAddress::where('user_id', Auth::id())->find($id);

        if (! $address) {
            return;
        }

        $this->addressId = $address->id;
        $this->cityId = $address->city_id;
        $this->address = (string) $address->address;
        $this->comment = (string) $address->note;
        $this->name = $address->name ?: $this->name;
        $this->phone = $address->phone ?: $this->phone;
    }

    /* ------------------------------------------------------------------ money */

    protected function deliveryCity(): ?DeliveryCity
    {
        return $this->cityId ? DeliveryCity::active()->find($this->cityId) : null;
    }

    public function shipping(): float
    {
        $city = $this->deliveryCity();

        return $city
            ? app(\App\Services\Delivery\DeliveryCalculator::class)->fee($city, $this->lines())
            : 0.0;
    }

    protected function method(): ?PaymentMethod
    {
        return PaymentMethod::active()->where('code', $this->payment)->first();
    }

    /**
     * The driver the chosen method names.
     *
     * It comes from the database rather than from the method's code, because a
     * method renamed in the admin would otherwise fall through to a driver that
     * does not exist — and only at the moment the customer pays.
     */
    protected function driverFor(): ?string
    {
        return $this->method()?->driver;
    }

    /** Whether the chosen method sends the customer to a bank that can take it. */
    public function isOnline(): bool
    {
        $method = $this->method();

        return $method
            && $method->is_online
            && $method->driver
            && app(PaymentManager::class)->has($method->driver);
    }

    public function isInstallment(): bool
    {
        return $this->driverFor() === 'bog-installment';
    }

    /** ZERO carries no handling fee: on that plan the shop pays the interest. */
    protected function isZeroPlan(): bool
    {
        return $this->method()?->installment_type === BogInstallment::ZERO;
    }

    /* ------------------------------------------------------------------ place */

    public function place()
    {
        $this->validate();

        // `exists` cannot see is_active, and a method switched off while the
        // page was open must not be the one the order is placed on
        if (! $this->method()) {
            $this->addError('payment', __('checkout.payment_unavailable'));

            return null;
        }

        $lines = $this->lines();

        if (! $lines) {
            return $this->redirect(route('catalog'), navigate: false);
        }

        try {
            $order = DB::transaction(fn () => $this->createOrder($lines));
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        // the cart only empties once the order is safely on disk
        if (! $this->buyNow) {
            app(Cart::class)->clear();
        }

        // a guest has to be able to see the page they are about to land on
        session()->push('placed_orders', $order->id);
        $this->placedNumber = $order->number;

        if ($this->isOnline()) {
            return $this->sendToBank($order);
        }

        session()->flash('toast', __('checkout.placed', ['number' => $order->number]));

        return $this->redirect(route('order', $order->number), navigate: false);
    }

    /**
     * Everything that must not half-happen, in one transaction.
     *
     * Prices are re-read from the database inside it: a cart lives in the
     * browser, so its numbers are a request rather than a fact.
     */
    protected function createOrder(array $lines): Order
    {
        $ids = collect($lines)->pluck('id');
        $products = Product::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        $items = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $product = $products[$line['id']] ?? null;

            // stock never blocks a sale; status and pre-order do
            if (! $product || $product->status !== Product::STATUS_ACTIVE) {
                throw new RuntimeException(__('checkout.item_gone'));
            }

            if ($product->is_preorder) {
                throw new RuntimeException(__('checkout.preorder_in_cart', ['name' => $product->name]));
            }

            $qty = max(1, (int) $line['qty']);
            $price = (float) $product->price;
            $subtotal += $price * $qty;

            $items[] = [
                'product_id' => $product->id,
                'name'       => $line['name'],
                'price'      => $price,
                'qty'        => $qty,
                'sum'        => $price * $qty,
            ];
        }

        $shipping = $this->shipping();

        $order = Order::create([
            'number'           => Order::nextNumber(),
            'user_id'          => Auth::id(),
            'cart_id'          => $this->openCartId(),
            'name'             => $this->name,
            'phone'            => $this->phone,
            'email'            => $this->email ?: null,
            'delivery'         => 'courier',
            'delivery_city_id' => $this->cityId,
            'city'             => $this->deliveryCity()?->name,
            'address'          => $this->address,
            'comment'          => $this->comment ?: null,
            'payment'          => $this->payment,
            'payment_status'   => 'pending',
            'is_paid'          => false,
            'subtotal'         => $subtotal,
            'shipping'         => $shipping,
            'total'            => $subtotal + $shipping,
        ]);

        $order->items()->createMany($items);

        foreach ($items as $item) {
            // stock is a record, not a rule: it must not go negative on paper
            Product::whereKey($item['product_id'])
                ->where('stock', '>=', $item['qty'])
                ->decrement('stock', $item['qty']);

            Product::whereKey($item['product_id'])->increment('sales_count', $item['qty']);
        }

        $order->events()->create([
            'user_id' => Auth::id(),
            'type'    => 'status',
            'to'      => 'new',
            'note'    => __('order.placed_by_customer'),
        ]);

        // the basket becomes history rather than an abandoned cart
        if ($order->cart_id) {
            CartModel::whereKey($order->cart_id)->update(['status' => 'ordered']);
        }

        if (Auth::check() && $this->saveAddress) {
            $this->storeAddress();
        }

        return $order;
    }

    protected function openCartId(): ?int
    {
        return CartModel::where('status', 'open')
            ->when(
                Auth::check(),
                fn ($q) => $q->where('user_id', Auth::id()),
                fn ($q) => $q->where('session_id', session()->getId()),
            )
            ->value('id');
    }

    /* ------------------------------------------------------------------ payment */

    /**
     * Hands the customer to the bank.
     *
     * Instalments open the bank's own calculator in the browser, because the
     * term is chosen there; anything else is a redirect. Either way the order
     * already exists and is unpaid, so a customer who walks away leaves
     * something we can call about rather than nothing.
     */
    protected function sendToBank(Order $order)
    {
        $driver = $this->driverFor();
        $payments = app(PaymentManager::class);

        if (! $driver || ! $payments->has($driver)) {
            return $this->fallBackToCall($order, "no driver for payment method [{$this->payment}]");
        }

        // each lender refuses below its own floor; better to say so than to be refused
        $minimum = (float) ($this->method()->min_total ?? 0);

        if ($minimum > 0 && (float) $order->total < $minimum) {
            $this->dispatch('toast', type: 'error', message: __('checkout.method_min', [
                'amount' => money($minimum),
            ]));

            return $this->redirect(route('order', $order->number), navigate: false);
        }

        if ($driver === 'bog-installment') {
            return $this->openCalculator($order);
        }

        try {
            return redirect()->away($payments->start($order, $driver));
        } catch (\Throwable $e) {
            report($e);

            return $this->fallBackToCall($order, $e->getMessage());
        }
    }

    /**
     * Opens the bank's calculator over the page.
     *
     * The widget is told the sum the customer will actually be lent: on the
     * interest-bearing plan that includes our handling fee, on the zero plan it
     * does not. Quoting one figure and applying for another would show terms
     * the customer never agreed to.
     */
    protected function openCalculator(Order $order)
    {
        $method = $this->method();
        $minimum = (float) ($method->min_total ?? config('bog.installment.min_total', 100));

        if ((float) $order->total < $minimum) {
            $this->dispatch('toast', type: 'error', message: __('checkout.installment_min', [
                'amount' => money($minimum),
            ]));

            return $this->redirect(route('order', $order->number), navigate: false);
        }

        $fee = $this->isZeroPlan() ? 0.0 : (float) config('bog.installment.handling_fee', 0.05);

        $this->dispatch($this->isZeroPlan() ? 'bog:installment-part' : 'bog:installment',
            amount: round((float) $order->total * (1 + $fee), 2),
            url: route('payment.bog.installment', $order->number),
        );

        // the page stays where it is: the calculator is a modal over it
        return null;
    }

    /** The order stands; only the payment failed to start. */
    protected function fallBackToCall(Order $order, string $reason)
    {
        $order->events()->create([
            'type' => 'payment',
            'note' => __('order.payment_start_failed').' — '.$reason,
        ]);

        session()->flash('toast', __('checkout.payment_failed'));

        return $this->redirect(route('order', $order->number), navigate: false);
    }

    protected function storeAddress(): void
    {
        $address = UserAddress::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'city_id' => $this->cityId,
                'address' => $this->address,
            ],
            [
                'name'  => $this->name,
                'phone' => $this->phone,
                'note'  => $this->comment ?: null,
            ],
        );

        if (! UserAddress::where('user_id', Auth::id())->where('is_default', true)->exists()) {
            $address->makeDefault();
        }
    }

    /* ------------------------------------------------------------------ lines */

    protected function lines(): array
    {
        if (! $this->buyNow) {
            return app(Cart::class)->lines();
        }

        $product = Catalog::productQuery()->findOrFail($this->productId);
        $card = Catalog::card($product);

        return [[
            'id'       => $product->id,
            'name'     => trim($card['brand'].' '.$card['name']),
            'cat'      => $card['cat'],
            'img'      => $card['thumb'],
            'price'    => (float) $product->price,
            'qty'      => $this->qty,
            'sum'      => (float) $product->price * $this->qty,
            'weight'   => $product->weight,
            'length'   => $product->length,
            'width'    => $product->width,
            'height'   => $product->height,
            'is_bulky' => (bool) $product->is_bulky,
        ]];
    }

    public function increment(): void
    {
        if ($this->buyNow) {
            $this->qty = min(99, $this->qty + 1);
        }
    }

    public function decrement(): void
    {
        if ($this->buyNow) {
            $this->qty = max(1, $this->qty - 1);
        }
    }

    /* ------------------------------------------------------------------ render */

    public function render()
    {
        $lines = $this->lines();
        $subtotal = array_sum(array_column($lines, 'sum'));
        $shipping = $this->shipping();
        $total = $subtotal + $shipping;

        return view('livewire.pages.checkout', [
            'lines'    => $lines,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total'    => $total,
            'cities'   => DeliveryCity::active()->withTranslation()->get(),
            'methods'  => PaymentMethod::active()->withTranslation()->get()
                ->filter(fn (PaymentMethod $m) => $m->fitsTotal($total))
                ->values(),
            'addresses' => Auth::check()
                ? UserAddress::where('user_id', Auth::id())->with('city')->orderByDesc('is_default')->get()
                : collect(),
        ])->extends('layouts.app', [
            'page'    => 'checkout',
            'nav'     => 'catalog',
            'tab'     => '',
            'pageCss' => 'checkout',
            'title'   => __('checkout.title').' — ELIO',
        ])->section('content');
    }
}