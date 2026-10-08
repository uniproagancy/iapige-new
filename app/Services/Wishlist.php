<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Wishlist for signed-in customers only; guests are asked to sign in. */
class Wishlist
{
    public function available(): bool
    {
        return Auth::check();
    }

    public function ids(): array
    {
        return $this->available()
            ? DB::table('wishlists')->where('user_id', Auth::id())->pluck('product_id')->all()
            : [];
    }

    public function has(int|string $id): bool
    {
        return $this->available()
            && DB::table('wishlists')->where('user_id', Auth::id())->where('product_id', (int) $id)->exists();
    }

    /** @return bool the new state */
    public function toggle(int|string $id): bool
    {
        if (! $this->available()) {
            return false;
        }

        $changed = Auth::user()->wishlist()->toggle([(int) $id]);
        $added = (bool) $changed['attached'];

        app(InteractionLog::class)->record(
            $added ? ProductInteraction::WISH_ADD : ProductInteraction::WISH_REMOVE,
            (int) $id,
        );

        return $added;
    }

    public function count(): int
    {
        return $this->available()
            ? DB::table('wishlists')->where('user_id', Auth::id())->count()
            : 0;
    }
}
