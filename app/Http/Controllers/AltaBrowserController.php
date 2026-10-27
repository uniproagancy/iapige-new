<?php

namespace App\Http\Controllers;

use App\Jobs\ImportProductJob;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaImageCache;
use App\Support\Html;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class AltaBrowserController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        $rows = $this->b2b($supplier)->all();
        $minimum = max(1, (int) ($supplier->config['min_quantity'] ?? 2));
        $includeOutOfStock = $request->boolean('include_out_of_stock');
        $items = [];

        foreach ($rows as $row) {
            $code = (string) ($row['item'] ?? '');

            if (! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $code)
                || (! $includeOutOfStock && ($row['quantity'] ?? 0) < $minimum)) {
                continue;
            }

            $saved = $this->directory($supplier).DIRECTORY_SEPARATOR.$code.'.json';
            $document = is_file($saved) ? json_decode(File::get($saved), true) : [];
            $url = $document['source_url'] ?? null;
            $items[] = [
                'item' => $code,
                'name' => $row['name'] ?? $row['model'] ?? $code,
                'quantity' => (int) $row['quantity'],
                'url' => is_string($url) && $this->productUrl($url, (int) ($document['product']['id'] ?? 0)) ? $url : null,
            ];
        }

        return response()->json(['items' => $items, 'minimum_quantity' => $minimum,
            'include_out_of_stock' => $includeOutOfStock, 'source' => 'alta_b2b']);
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        abort_if(strlen($request->getContent()) > 4194304, 413);
        $input = $request->validate([
            'expected_item' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/D'],
            'assets' => ['required', 'array', 'min:1', 'max:8'],
            'assets.*.url' => ['required', 'url', 'max:2048'],
            'assets.*.base64' => ['required', 'string', 'max:1400000'],
            'document' => ['required', 'array'],
            'document.schema' => ['required', 'in:iapi.alta.visible-product.v1'],
            'document.source_url' => ['required', 'url', 'max:2048'],
            'document.collected_at' => ['required', 'date'],
            'document.product' => ['required', 'array'],
            'document.product.id' => ['required', 'integer', 'min:1'],
            'document.product.barCode' => ['required', 'string', 'max:64'],
            'document.product.name' => ['required', 'string', 'max:255'],
            'document.product.price' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'document.product.previousPrice' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'document.product.description' => ['nullable', 'string', 'max:120000'],
            'document.product.brandName' => ['nullable', 'string', 'max:255'],
            'document.product.categoryName' => ['nullable', 'string', 'max:255'],
            'document.product.imageUrl' => ['required', 'url', 'max:2048'],
            'document.product.images' => ['required', 'array', 'min:1', 'max:40'],
            'document.product.images.*' => ['required', 'url', 'max:2048'],
            'document.product.specificationGroup' => ['required', 'array', 'min:1', 'max:50'],
            'document.product.specificationGroup.*.groupName' => ['nullable', 'string', 'max:255'],
            'document.product.specificationGroup.*.specifications' => ['required', 'array', 'max:200'],
            'document.product.specificationGroup.*.specifications.*.specificationName' => ['required', 'string', 'max:255'],
            'document.product.specificationGroup.*.specifications.*.specificationMeaning' => ['required', 'string', 'max:4000'],
        ]);
        $code = $input['expected_item'];
        $document = $input['document'];
        $product = $document['product'];
        $collected = Carbon::parse($document['collected_at']);

        if ($product['barCode'] !== $code || ! $this->productUrl($document['source_url'], (int) $product['id'])
            || $collected->lt(now()->subMinutes(15)) || $collected->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['document' => __('admin.alta_invalid_export')]);
        }

        foreach (array_merge([$product['imageUrl']], $product['images']) as $url) {
            if (! $this->imageUrl($url)) {
                throw ValidationException::withMessages(['document' => __('admin.alta_invalid_export')]);
            }
        }

        $assets = AltaImageCache::validate($input['assets'], array_merge([$product['imageUrl']], $product['images']));

        if ($supplier->markup((float) $product['price']) !== (float) $product['price']) {
            throw ValidationException::withMessages(['document.product.price' => __('admin.alta_price_parity')]);
        }

        $existing = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $code)->with('product')->first()?->product;
        $retail = (float) $product['price'];
        $previousRetail = (float) ($product['previousPrice'] ?? 0) > $retail ? (float) $product['previousPrice'] : null;
        $conflict = null;

        if ($existing?->price_lock && ((float) $existing->price !== $retail
            || ($existing->old_price !== null ? (float) $existing->old_price : null) !== $previousRetail)) {
            $conflict = 'price_lock';
        }

        if ($existing && ($promotion = $existing->livePromotions()->first())
            && $existing::promoFrom($promotion->pivot, $retail) !== null) {
            $conflict = 'promotion';
        }

        if ($conflict !== null) {
            return response()->json(['queued' => false, 'conflict' => $conflict, 'item' => $code,
                'retail_price' => $retail, 'selling_price' => $existing->sellingPrice()], 409);
        }

        // Ignore browser stock. The worker asks the authenticated B2B source again.
        $document['product']['storageQuantity'] = 0;
        $document['availabilityInStores'] = [];
        $document['product']['description'] = Html::safe($product['description'] ?? null);
        $intent = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR).implode('', array_map(fn ($bytes) => hash('sha256', $bytes), $assets)));
        $document['received_at'] = now()->toIso8601String();
        $directory = $this->directory($supplier);
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.DIRECTORY_SEPARATOR.$code.'.json';

        return Cache::lock('alta-browser:'.$code, 30)->block(3, function () use ($supplier, $document, $path, $collected, $code, $assets, $directory, $intent) {
            $queuedKey = 'alta-browser-queued:'.$supplier->id.':'.$code;
            $queued = Cache::get($queuedKey);

            if (($queued['intent'] ?? null) === $intent && is_file($path) && hash_file('sha256', $path) === ($queued['source_hash'] ?? null)) {
                return response()->json(['queued' => true, 'replayed' => true, 'item' => $code,
                    'retail_price' => (float) $document['product']['price']], 202);
            }

            if (is_file($path)) {
                $previous = json_decode(File::get($path), true);

                if (isset($previous['collected_at']) && Carbon::parse($previous['collected_at'])->gt($collected)) {
                    abort(409, __('admin.alta_invalid_export'));
                }
            }

            AltaImageCache::store($assets, $directory);

            $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            File::put($temporary, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($temporary, 0600);
            rename($temporary, $path);
            ImportProductJob::dispatch($supplier->id, $code)->onQueue('import:alta');
            Cache::put($queuedKey, ['intent' => $intent, 'source_hash' => hash_file('sha256', $path)], now()->addDay());

            return response()->json(['queued' => true, 'item' => $code, 'retail_price' => (float) $document['product']['price']], 202);
        });
    }

    public function status(Request $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        $input = $request->validate(['item' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/D']]);
        $offer = ProductOffer::where('supplier_id', $supplier->id)->where('external_id', $input['item'])->with('product')->first();
        $path = $this->directory($supplier).DIRECTORY_SEPARATOR.$input['item'].'.json';
        $document = is_file($path) ? json_decode(File::get($path), true) : null;
        $product = $offer?->product;
        $retail = isset($document['product']['price']) ? (float) $document['product']['price'] : null;
        $selling = $product?->sellingPrice();
        $images = $product?->images()->count() ?? 0;
        $fresh = isset($document['collected_at']) && Carbon::parse($document['collected_at'])->gte(now()->subSeconds((int) ($this->config($supplier)['retail_max_age'] ?? 86400)));
        $previousRetail = $retail !== null && (float) ($document['product']['previousPrice'] ?? 0) > $retail ? (float) $document['product']['previousPrice'] : null;
        $completed = Cache::get('alta-browser-complete:'.$supplier->id.':'.$input['item']);
        $processed = $completed && ($completed['images_complete'] ?? false) && is_file($path) && hash_file('sha256', $path) === $completed['hash'];
        $matches = $processed && $fresh && $images > 0 && $retail !== null && $selling === $retail
            && ($product?->old_price !== null ? (float) $product->old_price : null) === $previousRetail;

        return response()->json(['item' => $input['item'], 'imported' => $offer !== null,
            'synced_at' => $offer?->synced_at, 'product_id' => $offer?->product_id,
            'price' => $product?->price, 'old_price' => $product?->old_price,
            'selling_price' => $selling, 'retail_price' => $retail, 'matches_export' => $matches,
            'processed_export' => (bool) $processed,
            'retail_fresh' => $fresh, 'price_lock' => (bool) $product?->price_lock,
            'stock' => $offer?->stock, 'images' => $images]);
    }

    protected function supplier(Request $request): Supplier
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $supplier = Supplier::where('code', 'alta')->where('is_active', true)->firstOrFail();
        abort_unless($supplier->driver === AltaDriver::class, 409);

        return $supplier;
    }

    protected function config(Supplier $supplier): array
    {
        return array_replace((array) config('services.alta', []), $supplier->config ?? []);
    }

    protected function b2b(Supplier $supplier): AltaB2bClient
    {
        return new AltaB2bClient($this->config($supplier));
    }

    protected function directory(Supplier $supplier): string
    {
        return ($this->config($supplier)['retail_directory'] ?? null) ?: storage_path('app/private/alta-retail');
    }

    protected function productUrl(string $url, int $id): bool
    {
        $parts = parse_url($url);

        return $id > 0 && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'alta.ge' && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && str_ends_with(rtrim($parts['path'] ?? '', '/'), '-p'.$id);
    }

    protected function imageUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['imgstore.alta.ge', 'alta.ge'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['port']) || $parts['port'] === 443);
    }
}
