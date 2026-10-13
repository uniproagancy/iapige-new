<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a list of wanted products into the lines of an order.
 *
 * There are two ways into the catalogue now — a customer's own checkout and an
 * operator placing the order for them over the phone — and both have to apply
 * exactly the same rules: the price comes from the database, a product that is
 * no longer on sale cannot be ordered, and stock moves once the order exists.
 * Written twice, the two would have drifted the first time one of those rules
 * changed, and the one nobody was looking at would have gone on charging the
 * old price.
 */
class OrderLines
{
    /**
     * Prices a set of wanted products, locking each row.
     *
     * @param  array<int, array{id:int, qty:int, name?:string}>  $wanted
     * @return array{items: array<int, array<string, mixed>>, subtotal: float}
     *
     * @throws RuntimeException when something in the list cannot be sold
     */
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

            // stock never blocks a sale; status and pre-order do
            if (! $product || $product->status !== Product::STATUS_ACTIVE) {
                throw new RuntimeException(__('checkout.item_gone'));
            }

            if ($product->is_preorder) {
                throw new RuntimeException(__('checkout.preorder_in_cart', ['name' => $product->name]));
            }

            $qty = max(1, (int) $line['qty']);

            /*
             * Read here and nowhere else.
             *
             * Whatever asked for this line — a cart in somebody's browser, or
             * an operator's screen that has been open for half an hour — its
             * price is a request, not a fact. sellingPrice() is the same
             * method the product page and the cart show, so an order cannot
             * charge the shelf price for something advertised at a campaign
             * price.
             */
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

    /**
     * Writes the lines onto an order and moves the stock.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function attach(Order $order, array $items): void
    {
        $order->items()->createMany($items);

        foreach ($items as $item) {
            if (! $item['product_id']) {
                continue;
            }

            // stock is a record, not a rule: it must not go negative on paper
            Product::whereKey($item['product_id'])
                ->where('stock', '>=', $item['qty'])
                ->decrement('stock', $item['qty']);

            Product::whereKey($item['product_id'])->increment('sales_count', $item['qty']);
        }
    }

    /**
     * What the courier would be paid for these lines.
     *
     * The calculator wants the measurements, which the order's own items do
     * not carry — so they are read from the products, in one query.
     *
     * @param  array<int, array{product_id:?int, qty:int}>  $items
     * @return array<int, array<string, mixed>>
     */
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

    /** Both halves of writing an order's lines, for callers with nothing else to do. */
    public function write(Order $order, array $wanted): array
    {
        return DB::transaction(function () use ($order, $wanted) {
            $priced = $this->price($wanted);

            $this->attach($order, $priced['items']);

            return $priced;
        });
    }
}
