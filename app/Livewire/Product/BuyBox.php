<?php

namespace App\Livewire\Product;

use App\Models\Product;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class BuyBox extends Component
{
    #[Locked]
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
        $this->quantityChanged();
    }

    public function decrement(): void
    {
        $this->qty = max(1, $this->qty - 1);
        $this->quantityChanged();
    }

    #[On('product-quantity-changed')]
    public function syncQuantity(int $productId, int $qty): void
    {
        if ($productId === $this->productId) {
            $this->qty = max(1, min(99, $qty));
        }
    }

    protected function quantityChanged(): void
    {
        $this->dispatch('product-quantity-changed', productId: $this->productId, qty: $this->qty);
    }

    public function add(): void
    {
        $this->qty = max(1, min(99, $this->qty));
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
