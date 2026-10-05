<?php

namespace App\Livewire;

use App\Services\Wishlist;
use Livewire\Attributes\On;
use Livewire\Component;

/** The badge on the header's heart button. */
class WishlistCount extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshState();
    }

    #[On('wishlist-updated')]
    public function refreshState(): void
    {
        $this->count = app(Wishlist::class)->count();
    }

    public function render()
    {
        return view('livewire.wishlist-count');
    }
}
