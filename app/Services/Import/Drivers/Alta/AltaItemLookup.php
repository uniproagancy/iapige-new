<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 | What Alta B2B says right now about one item, next to what the site holds.
 |
 | The console command and the admin URL both answer from here, so a check
 | by hand reads the same fields wherever it is made. Nothing is written:
 | the scheduled stock sync stays the only thing that changes stock.
 */
class AltaItemLookup
{
    /**
     * Accepts the bare B2B code or the sku the admin shows (alta-171840).
     *
     * @return array<string, mixed>
     *
     * @throws AltaUnavailable
     */
    public function check(Supplier $supplier, string $item): array
    {
        $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);
        $code = self::code($item);

        /* An empty item asks Alta for the whole price list, which is the
           full sync's job, not a single check's. */
        if ($code === '') {
            throw new InvalidArgumentException('An Alta item code is required.');
        }

        $client = new AltaB2bClient($config);
        $raw = $client->raw($code);

        $quantity = $raw ? $client->quantity($raw['qty_text'] ?? null) : 0;
        $stored = DB::table('supplier_stocks')->where('supplier_id', $supplier->id)->where('external_id', $code)->first();
        $offer = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $code)->with('product')->first();
        $product = $offer?->product;

        return [
            'item' => $code,
            'b2b_now' => $raw ?? 'not on the B2B list',
            'b2b_quantity' => $quantity,
            'site_would_keep' => (new AltaDriver($supplier))->available(['quantity' => $quantity]),
            'minimum_quantity' => max(1, (int) ($supplier->config['min_quantity'] ?? 2)),
            'last_alta_stock' => $stored ? ['quantity' => (int) $stored->quantity, 'at' => $stored->synced_at] : null,
            'site' => $product ? [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'status' => $product->status,
                'stock' => (int) $product->stock,
                'alta_offer_stock' => (int) $offer->stock,
                'price' => (float) $product->price,
                'shown' => Product::listable()->whereKey($product->id)->exists(),
            ] : 'not on the site',
        ];
    }

    public static function code(string $item): string
    {
        return (string) preg_replace('/\Aalta-/i', '', trim($item));
    }
}
