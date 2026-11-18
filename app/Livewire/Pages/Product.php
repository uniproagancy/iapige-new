<?php

namespace App\Livewire\Pages;

use App\Models\Language;
use App\Models\Product as ProductModel;
use App\Services\Facebook\Pixel;
use App\Services\Social\SocialFeedLink;
use App\Support\Catalog;
use App\Support\Seo;
use Illuminate\Support\Str;
use Livewire\Component;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class Product extends Component
{
    public int $productId;

    protected ?ProductModel $resolved = null;

    public function mount(string $slug)
    {
        $product = Catalog::product($slug);

        if ($slug !== $product->slug) {
            return $this->redirect(route('product', $product->slug), navigate: false);
        }

        $this->productId = $product->getKey();
        $this->resolved = $product;

        $this->dispatch('pixel', app(Pixel::class)->viewContent([
            'id' => $product->id,
            'brand' => $product->brand?->name ?? '',
            'name' => $product->name,
            'price' => (float) $product->price,
        ]));
    }

    /* Languages this product has its own text in. English counts only when
       its name is English: a row holding the Georgian name again is no
       translation. */
    protected function languages(ProductModel $model): array
    {
        // withTranslation() loads the current language only, so the other rows are read here
        $names = $model->translations()->pluck('name', 'locale');

        return array_values(array_filter(
            array_keys(LaravelLocalization::getSupportedLocales()),
            fn (string $code) => $code === Language::defaultCode()
                || (filled($names[$code] ?? null) && ! preg_match('/\p{Georgian}/u', $names[$code])),
        ));
    }

    protected function written(): bool
    {
        return in_array(app()->getLocale(), $this->languages($this->resolved), true);
    }

    protected function inDefaultLanguage(ProductModel $model): string
    {
        $default = Language::defaultCode();

        $slug = $model->translations()->where('locale', $default)->value('slug') ?? $model->slug;

        return LaravelLocalization::getLocalizedURL($default, route('product', $slug), [], false);
    }

    public function render()
    {
        $model = $this->resolved ??= ProductModel::active()->withTranslation()
            ->with(['specs' => fn ($q) => $q->withTranslation()->with(['attribute' => fn ($a) => $a->withTranslation()])])
            ->findOrFail($this->productId);

        $data = Catalog::productPage($model);

        $data['model'] = $model;

        $data['colors'] = array_map(fn ($c) => [
            'code' => $c['code'] ?? Str::slug($c['label'] ?? $c['name'] ?? ''),
            'label' => $c['label'] ?? $c['name'] ?? '',
            'hex' => $c['hex'] ?? null,
            'url' => $c['url'] ?? null,
            'current' => (bool) ($c['current'] ?? false),
        ], $data['colors'] ?? []);

        $data['configs'] = array_map(fn ($i) => [
            'code' => $i['code'] ?? Str::slug($i['label'] ?? $i['name'] ?? ''),
            'label' => $i['label'] ?? $i['name'] ?? '',
            'note' => $i['note'] ?? null,
            'url' => $i['url'] ?? null,
            'current' => (bool) ($i['current'] ?? false),
        ], $data['configs'] ?? ($data['config']['items'] ?? []));

        $data['bundleIds'] = $data['bundleIds'] ?? array_column($data['bundle'] ?? [], 'id');

        $name = product_title($data['product']['brand'], $data['product']['name']);

        return view('livewire.pages.product', $data)
            ->extends('layouts.app', [
                'page' => 'product',
                'nav' => 'catalog',
                'tab' => 'catalog',
                'pageCss' => 'product',
                'pageJs' => 'product',
                'seo' => array_replace([

                    'title' => $model->meta_title ?: $name.' — '.config('app.name'),
                    'description' => $model->meta_description
                        ?: ($data['product']['spec']
                            ?: $name.' — '.money($data['product']['price']).'. '.__('layout.description')),
                    'type' => 'product',
                    'image' => $data['product']['thumb'] ?? null,
                    'image_alt' => $name,
                    'price' => $data['product']['price'],
                    'availability' => Seo::availability($model),

                    'canonical' => $this->written() ? route('product', $model->slug) : $this->inDefaultLanguage($model),
                    'robots' => $this->written() ? 'index, follow' : 'noindex, follow',
                    'locales' => $this->languages($model),
                    'schema' => [
                        Seo::product($data['product'], $model),
                        Seo::breadcrumbs($data['product']['breadcrumbs']),
                    ],
                ], app(SocialFeedLink::class)->metadata($model->getKey(), request())),
            ])
            ->section('content');
    }
}
