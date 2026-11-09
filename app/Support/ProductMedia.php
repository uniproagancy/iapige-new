<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;

class ProductMedia
{
    private const MODELS = ['E', 'C', 'D', 'N', 'K', 'Z', 'F', 'B', 'i', 'S', 'G'];

    public function videos(Product $product): array
    {
        if (! $product->getKey() || ! preg_match('/\ATREE1-[A-Za-z]-\d{3}\z/D', (string) $product->sku)) {
            return [];
        }

        /* Media stays with the reviewed supplier row, so another offer cannot
           replace this model's clips or expose private supplier fields. */
        $rows = DB::table('supplier_stocks as stock')
            ->join('suppliers as supplier', 'supplier.id', '=', 'stock.supplier_id')
            ->join('product_offers as offer', function (JoinClause $join): void {
                $join->on('offer.supplier_id', '=', 'stock.supplier_id')
                    ->on('offer.external_id', '=', 'stock.external_id');
            })
            ->where('offer.product_id', $product->getKey())
            ->where('supplier.code', 'christmas-trees-1')
            ->limit(2)
            ->get(['stock.data', 'offer.external_id', 'supplier.code as supplier_code']);

        if ($rows->count() !== 1) {
            return [];
        }

        $row = $rows->first();
        if ($row->supplier_code !== 'christmas-trees-1'
            || $row->external_id !== $product->sku
            || ! is_string($row->data) || strlen($row->data) > 100000) {
            return [];
        }

        try {
            $data = json_decode($row->data, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($data) || ! is_array($data['media'] ?? null)) {
            return [];
        }

        $model = $data['model'] ?? null;
        $clips = $data['media']['videos'] ?? null;
        if (($data['profile'] ?? null) !== 'christmas-trees-1-v1'
            || ! is_string($model) || ! in_array($model, self::MODELS, true)
            || ! preg_match('/\ATREE1-'.preg_quote($model, '/').'-\d{3}\z/D', $row->external_id)
            || ! is_array($clips) || ! array_is_list($clips) || count($clips) > 2) {
            return [];
        }

        $disk = Storage::disk('public');
        $prefix = 'supplier-media/trees-1/'.preg_quote($model, '~').'/';
        $videos = [];
        $seen = [];

        foreach ($clips as $clip) {
            $path = is_array($clip) ? ($clip['path'] ?? null) : null;
            $poster = is_array($clip) ? ($clip['poster'] ?? null) : null;
            if (! is_string($path) || ! is_string($poster)
                || ! preg_match('~\A'.$prefix.'video-[12]-[a-f0-9]{12}\.mp4\z~D', $path)
                || ! preg_match('~\A'.$prefix.'photo-[12]-[a-f0-9]{12}\.(?:jpg|png)\z~D', $poster)
                || isset($seen[$path]) || ! $disk->exists($path) || ! $disk->exists($poster)) {
                continue;
            }

            $seen[$path] = true;
            $videos[] = ['url' => $disk->url($path), 'poster' => $disk->url($poster)];
        }

        return $videos;
    }
}
