<?php

namespace App\Livewire;

use App\Services\Cart;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Contents of the cart drawer. The drawer shell (<aside id="cart" hidden>)
 * stays outside the component so a re-render never closes the open drawer.
 *
 * Cards add products from the browser with:
 *     Livewire.dispatch('cart-add', { productId: 12, qty: 1 })
 * and every change broadcasts `cart-updated` { count, total } so the header
 * badge and the tab bar update without being components themselves.
 */
class CartDrawer extends Component
{
    #[On('cart-add')]
    public function add(int $productId, int $qty = 1): void
    {
        if (! $this->cart()->add($productId, $qty)) {
            $this->dispatch('toast', message: __('cart.unavailable'), type: 'error');
        }

        $this->broadcast();
    }

    public function increment(int $productId): void
    {
        $this->setQty($productId, +1);
    }

    public function decrement(int $productId): void
    {
        $this->setQty($productId, -1);
    }

    /**
     * The quantity is read back from the cart rather than from the view, so two
     * quick clicks cannot both act on the same stale number.
     */
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
            'lines'   => $this->cart()->lines(),
            'summary' => $this->cart()->summary(),
        ]);
    }
}