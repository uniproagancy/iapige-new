<?php

namespace App\Listeners;

use App\Models\Cart;
use Illuminate\Auth\Events\Login;

/** A guest fills a cart, then signs in — the items must follow. */
class MergeGuestCart
{
    public function handle(Login $event): void
    {
        // the session id is still the pre-login one here
        $guest = Cart::where('status', 'open')
            ->whereNull('user_id')
            ->where('session_id', session()->getId())
            ->with('items')
            ->latest('id')
            ->first();

        if (! $guest || $guest->items->isEmpty()) {
            return;
        }

        $mine = Cart::firstOrCreate(
            ['user_id' => $event->user->getKey(), 'status' => 'open'],
            ['session_id' => session()->getId(), 'last_activity_at' => now()],
        );

        if ($mine->is($guest)) {
            return;
        }

        foreach ($guest->items as $item) {
            $existing = $mine->items()->firstOrNew(['product_id' => $item->product_id]);
            $existing->qty = ($existing->exists ? $existing->qty : 0) + $item->qty;
            $existing->price = $item->price;
            $existing->save();
        }

        $guest->items()->delete();
        $guest->update(['status' => 'merged']);
        $mine->touchActivity();
    }
}