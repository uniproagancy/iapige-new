<?php

namespace App\Livewire;

use App\Services\Wishlist;
use Livewire\Component;

/** The heart on a product card / product page. */
class WishlistHeart extends Component
{
    public int $productId;
    public bool $on = false;
    public string $variant = 'card'; // card | side | bar

    public function mount(int $productId, string $variant = 'card'): void
    {
        $this->productId = $productId;
        $this->variant = $variant;
        $this->on = app(Wishlist::class)->has($productId);
    }

    public function toggle(): void
    {
        $wishlist = app(Wishlist::class);
        if (! $wishlist->available()) {
            $this->dispatch('open-auth');   // ask the visitor to sign in first
            return;
        }
        $this->on = $wishlist->toggle($this->productId);
        $this->dispatch('wishlist-updated');
    }

    public function render()
    {
        return view('livewire.wishlist-heart');
    }
}
