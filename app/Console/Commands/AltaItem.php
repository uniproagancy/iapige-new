<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AltaItem extends Command
{
    protected $signature = 'alta:item {item : B2B item code, or the sku as alta-171840}';

    protected $description = 'Show what Alta B2B says right now about one item, next to what the site holds. Changes nothing.';

    public function handle(): int
    {
        $supplier = Supplier::where('code', 'alta')->firstOrFail();
        $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);

        // the sku is what the admin shows, so it is accepted as well as the bare code
        $code = preg_replace('/\Aalta-/i', '', trim((string) $this->argument('item')));

        $client = new AltaB2bClient($config);

        try {
            $raw = $client->raw($code);
        } catch (AltaUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $quantity = $raw ? $client->quantity($raw['qty_text'] ?? null) : 0;
        $stored = DB::table('supplier_stocks')->where('supplier_id', $supplier->id)->where('external_id', $code)->first();
        $offer = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $code)->with('product')->first();
        $product = $offer?->product;

        $this->line(json_encode([
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
