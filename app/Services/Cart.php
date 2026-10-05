<?php

namespace App\Services;

use App\Models\Cart as CartModel;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Support\Catalog;
use Illuminate\Support\Facades\Auth;

class Cart
{
    protected ?CartModel $cart = null;

    public function __construct(protected InteractionLog $log)
    {
		
    }
	
	public function summary(): array
    {
        $total = $this->total();

        return [
            'count'   => $this->count(),
            'total'   => money($total),
            'money'   => money($total),
            'monthly' => $this->monthly(),
        ];
    }

    protected function cart(bool $create = true): ?CartModel
    {
        if ($this->cart) {
            return $this->cart;
        }

        $query = CartModel::where('status', 'open')->latest('id');

        $cart = Auth::check()
            ? $query->where('user_id', Auth::id())->first()
            : $query->whereNull('user_id')->where('session_id', session()->getId())->first();

        if (! $cart && $create) {
            $cart = CartModel::create([
                'user_id'          => Auth::id(),
                'session_id'       => session()->getId(),
                'last_activity_at' => now(),
            ]);
        }

        return $this->cart = $cart;
    }

    /** ['product_id' => qty] */
    public function raw(): array
    {
        return $this->cart(false)?->items()->pluck('qty', 'product_id')->all() ?? [];
    }

    public function lines(): array
    {
        $cart = $this->cart(false);

        if (! $cart) {
            return [];
        }

        $items = $cart->items()->with(['product' => fn ($q) => $q->withTranslation()->with(['brand', 'images'])])->get();

        return $items->filter(fn ($item) => $item->product)->map(function ($item) {
            $card = Catalog::card($item->product);

            return [
                'id'    => $item->product_id,
                'name'  => trim($card['brand'].' '.$card['name']),
                'cat'   => $card['cat'],
                'img'   => $card['thumb'],
                'price' => (float) $item->product->price,   // always the live price
                'qty'   => $item->qty,
                'sum'   => (float) $item->product->price * $item->qty,
				'weight'   => $item->product->weight,
                'length'   => $item->product->length,
                'width'    => $item->product->width,
                'height'   => $item->product->height,
                'is_bulky' => (bool) $item->product->is_bulky,
            ];
        })->values()->all();
    }

   public function add(string $id, int $qty = 1): bool
    {
        $product = Product::active()->whereKey((int) $id)->first();

        // stock is unreliable across suppliers, so only the status decides;
        // a pre-order is listed but cannot be bought yet
        if (! $product?->isSellable()) {
            return false;
        }

        $cart = $this->cart();
        $item = $cart->items()->firstOrNew(['product_id' => $product->id]);
        $item->qty = min(99, ($item->exists ? $item->qty : 0) + max(1, $qty));
        $item->price = $product->price;
        $item->save();

        $cart->touchActivity();
        $this->log->record(ProductInteraction::CART_ADD, $product->id, $qty, (float) $product->price);

        return true;
    }

    public function setQty(string $id, int $qty): void
    {
        $cart = $this->cart(false);
        $item = $cart?->items()->where('product_id', (int) $id)->first();

        if (! $item) {
            return;
        }

        if ($qty < 1) {
            $this->remove($id);
            return;
        }

        $item->update(['qty' => min(99, $qty)]);
        $cart->touchActivity();
    }

    public function remove(string $id): void
    {
        $cart = $this->cart(false);
        $item = $cart?->items()->where('product_id', (int) $id)->first();

        if (! $item) {
            return;
        }

        $qty = $item->qty;
        $item->delete();
        $cart->touchActivity();

        $this->log->record(ProductInteraction::CART_REMOVE, (int) $id, $qty);
    }

    public function clear(): void
    {
        $cart = $this->cart(false);

        if (! $cart) {
            return;
        }

        foreach ($cart->items as $item) {
            $this->log->record(ProductInteraction::CART_REMOVE, $item->product_id, $item->qty);
        }

        $cart->items()->delete();
        $cart->touchActivity();
    }

    public function count(): int
    {
        return (int) array_sum($this->raw());
    }

    public function total(): float
    {
        return array_sum(array_column($this->lines(), 'sum'));
    }

    public function monthly(int $months = 12): int
    {
        return (int) round($this->total() / $months);
    }
}