<?php

namespace App\Livewire;

use App\Models\Product;
use App\Services\Cart;
use App\Services\Facebook\Pixel;
use Livewire\Attributes\On;
use Livewire\Component;

class CartDrawer extends Component
{
    #[On('cart-add')]
    public function add(int $productId, int $qty = 1): void
    {
        if (! $this->cart()->add($productId, $qty)) {
            $this->dispatch('toast', message: __('cart.unavailable'), type: 'error');
        } else {
            $this->trackAdd($productId, $qty);
        }

        $this->broadcast();
    }

    protected function trackAdd(int $productId, int $qty): void
    {
        $product = Product::withTranslation()->with('brand')->find($productId);

        if (! $product) {
            return;
        }

        $this->dispatch('pixel', app(Pixel::class)->addToCart([

            'id' => $product->id,
            'brand' => $product->brand?->name ?? '',
            'name' => $product->name,
            'price' => (float) $product->price,
            'qty' => $qty,
        ]));
    }

    public function increment(int $productId): void
    {
        $this->setQty($productId, +1);
    }

    public function decrement(int $productId): void
    {
        $this->setQty($productId, -1);
    }

    protected function setQty(int $productId, int $by): void
    {
        $cart = $this->cart();
        $current = (int) ($cart->raw()[$productId] ?? 0);

        if ($current === 0) {
            return;
        }

        $cart->setQty((string) $productId, $current + $by);
        $this->broadcast();
    }

    public function remove(int $productId): void
    {
        $this->cart()->remove((string) $productId);
        $this->broadcast();
    }

    public function clear(): void
    {
        $this->cart()->clear();
        $this->broadcast();
    }

    protected function broadcast(): void
    {
        $this->dispatch('cart-updated', ...$this->cart()->summary());
    }

    protected function cart(): Cart
    {
        return app(Cart::class);
    }

    public function render()
    {
        return view('livewire.cart-drawer', [
            'lines' => $this->cart()->lines(),
            'summary' => $this->cart()->summary(),
        ]);
    }
}
