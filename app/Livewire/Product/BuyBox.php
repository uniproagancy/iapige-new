<?php

namespace App\Livewire\Product;

use App\Models\Product;
use Livewire\Component;

/**
 * Quantity, colour and configuration options and "add to cart" for the product page.
 * Adding dispatches `cart-add`, which App\Livewire\CartDrawer picks up.
 */
class BuyBox extends Component
{
    public int $productId;
    public int $qty = 1;
    public ?string $color = null;
    public ?string $config = null;
    public bool $added = false;

    /** @var array<int, array{code:string,label:string,hex:?string}> */
    public array $colors = [];
    /** @var array<int, array{code:string,label:string,note:?string}> */
    public array $configs = [];

    public function mount(Product $product, array $colors = [], array $configs = []): void
    {
        $this->productId = $product->getKey();
        $this->colors = $colors;
        $this->configs = $configs;
        $this->color = $colors[0]['code'] ?? null;
        $this->config = $configs[1]['code'] ?? ($configs[0]['code'] ?? null);
    }

    public function increment(): void
    {
        $this->qty = min(99, $this->qty + 1);
    }

    public function decrement(): void
    {
        $this->qty = max(1, $this->qty - 1);
    }

    public function chooseColor(string $code)
    {
        return $this->switchTo($this->colors, $code, fn () => $this->color = $code);
    }

    public function chooseConfig(string $code)
    {
        return $this->switchTo($this->configs, $code, fn () => $this->config = $code);
    }

    /** An option that belongs to another product navigates; ours just selects. */
    protected function switchTo(array $options, string $code, callable $select)
    {
        $option = collect($options)->firstWhere('code', $code);

        if (! empty($option['url'])) {
            return $this->redirect($option['url'], navigate: false);
        }

        $select();

        return null;
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
