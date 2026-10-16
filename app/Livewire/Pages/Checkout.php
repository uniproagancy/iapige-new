<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\SendsToBank;
use App\Models\Cart as CartModel;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\UserAddress;
use App\Services\Cart;
use App\Services\Delivery\DeliveryCalculator;
use App\Services\Facebook\Pixel;
use App\Services\Orders\OrderLines;
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
    use SendsToBank;

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

    #[Validate('required|string|exists:payment_methods,code')]
    public string $payment = '';

    public bool $saveAddress = true;

    public ?int $addressId = null;

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

        $this->trackCheckoutStarted();
    }

    protected function snapshotForPixel(Order $order): OrderPixelData
    {
        $pixel = app(Pixel::class);
        $browser = $pixel->browserData();

        return OrderPixelData::updateOrCreate(['order_id' => $order->id], [
            'event_id' => Pixel::eventId('pu'),
            'fbp' => $browser['fbp'] ?? null,
            'fbc' => $browser['fbc'] ?? null,
            'ip' => $browser['client_ip_address'] ?? null,
            'user_agent' => $browser['client_user_agent'] ?? null,
            'source_url' => route('order', $order->number),
            'consented' => $pixel->consented(),
        ]);
    }

    protected function trackCheckoutStarted(): void
    {
        $lines = $this->lines();

        if (! $lines) {
            return;
        }

        $subtotal = array_sum(array_column($lines, 'sum'));

        $this->dispatch('pixel', app(Pixel::class)->initiateCheckout($lines, $subtotal + $this->shipping()));
    }

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

    protected function deliveryCity(): ?DeliveryCity
    {
        return $this->cityId ? DeliveryCity::active()->find($this->cityId) : null;
    }

    public function shipping(): float
    {
        $city = $this->deliveryCity();

        return $city
            ? app(DeliveryCalculator::class)->fee($city, $this->lines())
            : 0.0;
    }

    protected function method(): ?PaymentMethod
    {
        return PaymentMethod::active()->where('code', $this->payment)->first();
    }

    protected function driverFor(): ?string
    {
        return $this->method()?->driver;
    }

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

    public function place()
    {
        $this->validate();

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

        if (! $this->buyNow) {
            app(Cart::class)->clear();
        }

        session()->push('placed_orders', $order->id);
        $this->placedNumber = $order->number;

        $snapshot = $this->snapshotForPixel($order);

        if ($this->isOnline()) {

            return $this->sendToBank($order, $this->method());
        }

        if (app(Pixel::class)->purchase($order->loadMissing('items'), $snapshot->event_id)) {
            $snapshot->markSent();
        }

        session()->flash('toast', __('checkout.placed', ['number' => $order->number]));

        return $this->redirect(route('order', $order->number), navigate: false);
    }

    protected function createOrder(array $lines): Order
    {

        $priced = app(OrderLines::class)->price($lines);

        $items = $priced['items'];
        $subtotal = $priced['subtotal'];
        $shipping = $this->shipping();

        $order = Order::create([
            'number' => Order::nextNumber(),
            'user_id' => Auth::id(),
            'cart_id' => $this->openCartId(),
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email ?: null,
            'delivery' => 'courier',
            'delivery_city_id' => $this->cityId,
            'city' => $this->deliveryCity()?->name,
            'address' => $this->address,
            'comment' => $this->comment ?: null,
            'payment' => $this->payment,
            'payment_status' => 'pending',
            'is_paid' => false,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $subtotal + $shipping,
        ]);

        app(OrderLines::class)->attach($order, $items);

        $order->events()->create([
            'user_id' => Auth::id(),
            'type' => 'status',
            'to' => 'new',
            'note' => __('order.placed_by_customer'),
        ]);

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

    protected function storeAddress(): void
    {
        $address = UserAddress::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'city_id' => $this->cityId,
                'address' => $this->address,
            ],
            [
                'name' => $this->name,
                'phone' => $this->phone,
                'note' => $this->comment ?: null,
            ],
        );

        if (! UserAddress::where('user_id', Auth::id())->where('is_default', true)->exists()) {
            $address->makeDefault();
        }
    }

    protected function lines(): array
    {
        if (! $this->buyNow) {
            return app(Cart::class)->lines();
        }

        $product = Catalog::productQuery()->findOrFail($this->productId);
        $card = Catalog::card($product);

        return [[
            'id' => $product->id,
            'name' => trim($card['brand'].' '.$card['name']),
            'cat' => $card['cat'],
            'img' => $card['thumb'],
            'price' => $price = $product->sellingPrice(),
            'qty' => $this->qty,
            'sum' => $price * $this->qty,
            'weight' => $product->weight,
            'length' => $product->length,
            'width' => $product->width,
            'height' => $product->height,
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

    public function render()
    {
        $lines = $this->lines();
        $subtotal = array_sum(array_column($lines, 'sum'));
        $shipping = $this->shipping();
        $total = $subtotal + $shipping;

        return view('livewire.pages.checkout', [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $total,
            'cities' => DeliveryCity::active()->withTranslation()->get(),
            'methods' => PaymentMethod::active()->withTranslation()->get()
                ->filter(fn (PaymentMethod $m) => $m->fitsTotal($total))
                ->values(),
            'addresses' => Auth::check()
                ? UserAddress::where('user_id', Auth::id())->with('city')->orderByDesc('is_default')->get()
                : collect(),
        ])->extends('layouts.app', [
            'page' => 'checkout',
            'nav' => 'catalog',
            'tab' => '',
            'pageCss' => 'checkout',
            'title' => __('checkout.title').' — IAPI.GE',

            'seo' => ['robots' => 'noindex, nofollow'],
        ])->section('content');
    }
}
