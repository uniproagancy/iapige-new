<?php

namespace App\Livewire\Product;

use App\Models\Product;
use Livewire\Component;

/**
 * Quantity and "add to cart" for the product page.
 *
 * The colour and size options were here too, as buttons that asked the server
 * which product to open next. They are links in <x-product.variants> now, so
 * nothing about choosing a variant needs this component at all.
 * Adding dispatches `cart-add`, which App\Livewire\CartDrawer picks up.
 */
class BuyBox extends Component
{
    public int $productId;

    public int $qty = 1;

    public bool $added = false;

    public function mount(Product $product): void
    {
        $this->productId = $product->getKey();
    }

    public function increment(): void
    {
        $this->qty = min(99, $this->qty + 1);
    }

    public function decrement(): void
    {
        $this->qty = max(1, $this->qty - 1);
    }

    public function add(): void
    {
        $this->dispatch('cart-add', productId: $this->productId, qty: $this->qty);

        $this->added = true;
        $this->dispatch('flash-added');
    }

    public function render()
    {
        return view('livewire.product.buy-box', [
            'product' => Product::withTranslation()->findOrFail($this->productId),
        ]);
    }
}
