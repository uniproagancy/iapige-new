<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInteraction;
use Illuminate\Support\Facades\Auth;

class InteractionLog
{
    public function record(string $action, int $productId, int $qty = 1, ?float $price = null): void
    {
        ProductInteraction::create([
            'user_id'    => Auth::id(),
            'session_id' => session()->getId(),
            'product_id' => $productId,
            'action'     => $action,
            'qty'        => max(1, $qty),
            'price'      => $price ?? Product::whereKey($productId)->value('price'),
        ]);
    }
}