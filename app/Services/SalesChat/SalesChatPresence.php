<?php

namespace App\Services\SalesChat;

use App\Models\Language;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesChatPresence
{
    public const SECTIONS = ['home', 'catalog', 'product', 'cart', 'checkout', 'other'];

    public const SLUG_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N}._~-]*$/uD';

    public const WINDOW_SECONDS = 90;

    private const RETENTION_MINUTES = 15;

    private const VISITOR_LIMIT = 30;

    private const PRODUCT_LIMIT = 20;

    public function record(string $sessionId, string $section, ?string $slug): void
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503);

        DB::table('sales_chat_visitors')->upsert([[
            'session_hash' => hash_hmac('sha256', 'iapi.presence.session.v1:'.$sessionId, $key),
            'section' => $section,
            'product_slug' => $section === 'product' && $slug !== null ? $this->storedSlug($slug) : null,
            'last_seen_at' => now(),
        ]], ['session_hash'], ['section', 'product_slug', 'last_seen_at']);

        if (Cache::add('iapi.sales-chat.presence-prune', true, 60)) {
            $this->prune();
        }
    }

    public function prune(): int
    {
        return DB::table('sales_chat_visitors')->where('last_seen_at', '<', now()->subMinutes(self::RETENTION_MINUTES))->delete();
    }

    public function snapshot(): array
    {
        $active = DB::table('sales_chat_visitors')->where('last_seen_at', '>=', now()->subSeconds(self::WINDOW_SECONDS));
        $counts = (clone $active)->selectRaw('section, count(*) as total')->groupBy('section')->pluck('total', 'section');
        $productCounts = (clone $active)->whereNotNull('product_slug')
            ->selectRaw('product_slug, count(*) as total')->groupBy('product_slug')
            ->orderByDesc('total')->orderBy('product_slug')->limit(self::PRODUCT_LIMIT)->pluck('total', 'product_slug');
        $sample = (clone $active)->orderByDesc('last_seen_at')->orderBy('session_hash')
            ->limit(self::VISITOR_LIMIT)->get(['session_hash', 'section', 'product_slug', 'last_seen_at']);
        $slugs = $productCounts->keys()->merge($sample->pluck('product_slug'))
            ->filter(fn ($slug) => $slug !== null)->unique()->values()->all();
        $products = $this->products($slugs);

        return [
            'window_seconds' => self::WINDOW_SECONDS,
            'active_count' => (int) $counts->sum(),
            'sections' => $counts->map(fn ($total, string $section) => ['key' => $section, 'active_count' => (int) $total])->values()->all(),
            'products' => $productCounts->filter(fn ($total, string $slug) => $products->has($slug))
                ->map(fn ($total, string $slug) => $products[$slug] + ['active_count' => (int) $total])->values()->all(),
            'visitors' => $sample->map(function (object $visitor) use ($products): array {
                $product = $products->get($visitor->product_slug);

                return [
                    'code' => strtoupper(substr(hash('sha256', 'iapi.presence.display.v1:'.$visitor->session_hash), 0, 8)),
                    'section_key' => $visitor->section,
                    'product_name' => $product['name'] ?? null,
                    'product_url' => $product['url'] ?? null,
                    'active_at' => Carbon::parse($visitor->last_seen_at, config('app.timezone'))->utc()->toIso8601String(),
                ];
            })->all(),
        ];
    }

    private function storedSlug(string $slug): ?string
    {
        $locale = Language::defaultCode();
        $product = Product::active()->withTranslation($locale)
            ->whereTranslation('slug', $slug, app()->getLocale())->first()
            ?? Product::active()->withTranslation($locale)->whereTranslation('slug', $slug, false)->first();
        $canonical = $product?->translatedValue('slug', $locale);

        return $this->validSlug($canonical) ? $canonical : null;
    }

    private function products(array $slugs): Collection
    {
        if ($slugs === []) {
            return collect();
        }

        $locale = Language::defaultCode();

        return Product::active()->withTranslation()
            ->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale)->whereIn('slug', $slugs))
            ->get(['id'])->mapWithKeys(function (Product $product) use ($locale): array {
                $name = mb_substr(trim(strip_tags((string) $product->name)), 0, 160);
                $slug = $product->slug;
                $canonical = $product->translatedValue('slug', $locale);

                if ($name === '' || ! $this->validSlug($slug)) {
                    return [];
                }

                return [$canonical => ['name' => $name, 'url' => route('product', $slug)]];
            });
    }

    private function validSlug(?string $slug): bool
    {
        return $slug !== null && mb_strlen($slug) <= 190 && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }
}
