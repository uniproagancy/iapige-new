<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class ProductSelector
{
    public const MIN_PRICE = 500;

    public const COOLDOWN_DAYS = 7;

    public const TIMEZONE = 'Asia/Tbilisi';

    /**
     * @return array<int, array<string, int|float|string|null>>
     */
    public function select(
        string $pageId,
        CarbonInterface $at,
        int $limit = 5,
        array $excludeProductIds = [],
        array $usedCategoryIds = [],
    ): array {
        if ($limit < 1) {
            return [];
        }

        $local = CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE);
        $excluded = array_unique(array_merge($excludeProductIds, $this->reservedProductIds($pageId, $local)));
        $buckets = [];

        $query = Product::query()->active()
            ->where('is_preorder', false)
            ->where('stock', '>', 0)
            ->where('price', '>=', self::MIN_PRICE)
            ->whereNotNull('category_id')
            ->whereNotIn('id', $excluded)
            ->withTranslation('ka')
            ->with(['brand', 'images', 'livePromotions']);

        foreach ($query->lazyById(200) as $product) {
            if (! $snapshot = $this->snapshot($product)) {
                continue;
            }

            $category = (int) $snapshot['category_id'];
            $buckets[$category][] = $snapshot;
            usort($buckets[$category], fn (array $a, array $b) => $this->rank($a, $local) <=> $this->rank($b, $local));
            $buckets[$category] = array_slice($buckets[$category], 0, $limit);
        }

        $categoryCounts = array_count_values(array_map('intval', $usedCategoryIds));
        $selected = [];

        // Prefer an unused category each time, then balance repeats if choices are limited.
        while ($buckets && count($selected) < $limit) {
            $categories = array_keys($buckets);
            usort($categories, fn (int $a, int $b) => [
                $categoryCounts[$a] ?? 0, $this->rank($buckets[$a][0], $local),
            ] <=> [
                $categoryCounts[$b] ?? 0, $this->rank($buckets[$b][0], $local),
            ]);
            $category = $categories[0];
            $selected[] = array_shift($buckets[$category]);
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            if ($buckets[$category] === []) {
                unset($buckets[$category]);
            }
        }

        return $selected;
    }

    /**
     * Supply a freshly queried product when revalidating immediately before publishing.
     *
     * @return array<string, int|float|string|null>|null
     */
    public function snapshot(Product $product): ?array
    {
        $snapshot = $this->manualSnapshot($product);

        return $snapshot !== null && $product->category_id
            && $snapshot['price'] >= self::MIN_PRICE && $snapshot['old_price'] > $snapshot['price']
                ? $snapshot : null;
    }

    /** Manual Suite exports do not enter the automatic discount/price/cooldown selection. */
    public function manualSnapshot(Product $product): ?array
    {
        if (! $product->isLive() || ! $product->isSellable()) {
            return null;
        }

        $product->loadMissing(['translations', 'brand', 'images', 'livePromotions']);
        $price = round($product->sellingPrice(), 2);
        $base = (float) $product->price;
        $old = max($price < $base ? $base : 0, (float) ($product->old_price ?? 0));

        if (! is_finite($price) || ! is_finite($old) || $price <= 0) {
            return null;
        }

        $name = $this->plain((string) $product->translatedValue('name', 'ka'));
        $slug = trim((string) $product->translatedValue('slug', 'ka'));

        if ($name === '' || $slug === '') {
            return null;
        }

        $image = $product->images->first(fn ($image) => $this->localImagePath((string) $image->path) !== null);

        if (! $image) {
            return null;
        }

        $months = max(1, (int) config('shop.instalment_months', 24));

        return [
            'product_id' => (int) $product->getKey(),
            'title' => $this->plain(product_title($product->brand?->name, $name)),
            'url' => LaravelLocalization::getLocalizedURL('ka', route('product', $slug), [], true),
            'image_path' => str_replace('\\', '/', (string) $image->path),
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'price' => $price,
            'old_price' => $old > $price ? round($old, 2) : 0.0,
            'discount_percent' => $old > $price ? (int) round((1 - $price / $old) * 100) : 0,
            'monthly_price' => $product->monthlyPrice($months),
            'installment_months' => $months,
            'currency' => 'GEL',
        ];
    }

    public function localImagePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/')
            || preg_match('/(^|\/)\.\.?($|\/)|[\x00-\x1F:?<>|]/', $path)
            || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
            || config('filesystems.disks.public.driver') !== 'local') {
            return null;
        }

        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));

        if ($root === false || $resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }

        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalized = str_replace('\\', '/', $resolved);

        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $normalized = strtolower($normalized);
        }

        if (! str_starts_with($normalized, $prefix)) {
            return null;
        }

        $size = @getimagesize($resolved);

        return is_array($size) && in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $resolved : null;
    }

    protected function reservedProductIds(string $pageId, CarbonImmutable $at): array
    {
        $cutoff = $at->subDays(self::COOLDOWN_DAYS)->utc();

        return SocialPost::query()->where('page_id', $pageId)
            ->where(function (Builder $query) use ($at, $cutoff) {
                $query->where('scheduled_date', $at->toDateString())
                    ->orWhere('published_at', '>', $cutoff)
                    ->orWhere(fn (Builder $reserved) => $reserved
                        ->whereIn('status', [
                            SocialPost::STATUS_DRAFT, SocialPost::STATUS_READY,
                            SocialPost::STATUS_PUBLISHING, SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UNKNOWN,
                        ])
                        ->where('scheduled_for', '>', $cutoff)
                        ->where('scheduled_for', '<=', $at->endOfDay()->utc()));
            })->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function rank(array $snapshot, CarbonImmutable $at): string
    {
        return hash('sha256', $at->toDateString().'|'.$snapshot['product_id']);
    }

    protected function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
