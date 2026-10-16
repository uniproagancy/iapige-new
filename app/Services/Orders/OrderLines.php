<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderLines
{
    public function price(array $wanted): array
    {
        $products = Product::whereIn('id', collect($wanted)->pluck('id'))
            ->with('brand')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $items = [];
        $subtotal = 0.0;

        foreach ($wanted as $line) {
            $product = $products[$line['id']] ?? null;

            if (! $product || $product->status !== Product::STATUS_ACTIVE) {
                throw new RuntimeException(__('checkout.item_gone'));
            }

            if ($product->is_preorder) {
                throw new RuntimeException(__('checkout.preorder_in_cart', ['name' => $product->name]));
            }

            $qty = max(1, (int) $line['qty']);

            $price = $product->sellingPrice();
            $subtotal += $price * $qty;

            $items[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $line['name'] ?? trim(($product->brand?->name ?? '').' '.$product->name),
                'price' => $price,
                'qty' => $qty,
                'sum' => $price * $qty,
            ];
        }

        return ['items' => $items, 'subtotal' => $subtotal];
    }

    public function attach(Order $order, array $items): void
    {
        $order->items()->createMany($items);

        foreach ($items as $item) {
            if (! $item['product_id']) {
                continue;
            }

            Product::whereKey($item['product_id'])->increment('sales_count', $item['qty']);
        }
    }

    public function measured(array $items): array
    {
        $ids = collect($items)->pluck('product_id')->filter();

        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::whereIn('id', $ids)
            ->get(['id', 'weight', 'length', 'width', 'height', 'is_bulky'])
            ->keyBy('id');

        $measured = [];

        foreach ($items as $item) {
            $product = $products[$item['product_id']] ?? null;

            if (! $product) {
                continue;
            }

            $measured[] = [
                'qty' => max(1, (int) $item['qty']),
                'weight' => $product->weight,
                'length' => $product->length,
                'width' => $product->width,
                'height' => $product->height,
                'is_bulky' => (bool) $product->is_bulky,
            ];
        }

        return $measured;
    }

    public function write(Order $order, array $wanted): array
    {
        return DB::transaction(function () use ($order, $wanted) {
            $priced = $this->price($wanted);

            $this->attach($order, $priced['items']);

            return $priced;
        });
    }
}
