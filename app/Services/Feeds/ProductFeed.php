<?php

namespace App\Services\Feeds;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

abstract class ProductFeed
{
    protected array $config;

    protected int $written = 0;

    protected array $skipped = ['price' => 0, 'no_name' => 0, 'no_image' => 0, 'no_category' => 0];

    public function __construct()
    {
        $this->config = config('feeds.'.$this->key());
    }

    abstract protected function key(): string;

    abstract protected function writeItem(XMLWriter $xml, Product $product): void;

    public function generate(): string
    {
        $locale = $this->config['locale'];
        $previous = app()->getLocale();
        app()->setLocale($locale);

        $path = Storage::disk('local')->path($this->config['path']);
        @mkdir(dirname($path), 0755, true);

        $temporary = $path.'.tmp';

        $xml = new XMLWriter;
        $xml->openUri($temporary);
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $xml->startElement('channel');
        $xml->writeElement('title', $this->config['title']);
        $xml->writeElement('link', config('app.url'));
        $xml->writeElement('description', $this->config['description']);

        $this->query()->chunkById(200, function ($products) use ($xml) {
            foreach ($products as $product) {
                $this->writeItem($xml, $product);
            }

            gc_collect_cycles();
        });

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        rename($temporary, $path);
        app()->setLocale($previous);

        Log::info($this->key().' feed generated', ['items' => $this->written] + $this->skipped);

        return $path;
    }

    protected function query()
    {
        $excluded = $this->excludedCategoryIds();

        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->whereNotNull('category_id')
            ->when($excluded, fn ($q) => $q->whereNotIn('category_id', $excluded))
            ->when($this->config['exclude_brands'], fn ($q) => $q
                ->whereDoesntHave('brand', fn ($b) => $b->whereIn('slug', $this->config['exclude_brands'])))
            ->withTranslation()
            ->with([
                'images',
                'brand',
                'livePromotions',
                'category' => fn ($q) => $q->withTranslation()->with(['parent' => fn ($p) => $p->withTranslation()]),
                'offers.supplier',
            ]);
    }

    protected function excludedCategoryIds(): array
    {
        $ids = $this->config['exclude_categories'];

        $hidden = Category::where('in_feed', false)->pluck('id')->all();
        $ids = array_unique(array_merge($ids, $hidden));

        if (! $ids) {
            return [];
        }

        return Category::whereIn('id', $ids)->get()
            ->flatMap(fn (Category $c) => $c->descendantAndSelfIds())
            ->unique()->values()->all();
    }

    protected function prices(Product $product): array
    {
        $selling = $product->sellingPrice();
        $shelf = (float) $product->price;

        $was = $selling < $shelf
            ? $shelf
            : ($product->old_price && (float) $product->old_price > $selling ? (float) $product->old_price : null);

        return [$selling, $was];
    }

    protected function skip(Product $product, string $name, float $price, ?string $image): bool
    {
        if ($name === '') {
            $this->skipped['no_name']++;

            return true;
        }

        if ($price < $this->config['min_price']) {
            $this->skipped['price']++;

            return true;
        }

        if (! $image) {
            $this->skipped['no_image']++;

            return true;
        }

        return false;
    }

    protected function money(float $amount): string
    {
        return number_format(round($amount), 2, '.', '').' '.$this->config['currency'];
    }

    protected function description(Product $product, string $name): string
    {
        $text = trim(strip_tags((string) ($product->summary ?: $product->description)));

        return $this->clean($text !== '' ? $text : $name, 5000);
    }

    protected function image(Product $product): ?string
    {
        $first = $product->images->sortBy('sort_order')->first();

        if (! $url = $first?->url()) {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    protected function gallery(Product $product): array
    {
        return $product->images->sortBy('sort_order')->skip(1)->take(10)
            ->map->url()->filter()->values()->all();
    }

    protected function productType(Category $category): string
    {
        return collect($category->ancestorsAndSelf())
            ->map(fn (Category $c) => $c->name)
            ->filter()
            ->implode(' > ');
    }

    protected function priceBand(float $price): string
    {
        return match (true) {
            $price < 200 => '0-200',
            $price < 500 => '200-500',
            $price < 1000 => '500-1000',
            $price < 2500 => '1000-2500',
            default => '2500+',
        };
    }

    protected function clean(string $value, int $limit): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', strip_tags($value));

        return mb_substr(trim($value), 0, $limit);
    }
}
