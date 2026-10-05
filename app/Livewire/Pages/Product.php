<?php

namespace App\Livewire\Pages;

use App\Services\Facebook\Pixel;
use App\Support\Catalog;
use App\Support\Seo;
use Illuminate\Support\Str;
use Livewire\Component;

class Product extends Component
{
    public int $productId;

    public function mount(string $slug)
    {
        $product = Catalog::product($slug);

        if ($slug !== $product->slug) {
            return $this->redirect(route('product', $product->slug), navigate: false);
        }

        $this->productId = $product->getKey();

        // in mount rather than render: switching a colour re-renders the page,
        // and that is the same visit rather than a second look at the product
        $this->dispatch('pixel', app(Pixel::class)->viewContent([
            'id' => $product->id,
            'brand' => $product->brand?->name ?? '',
            'name' => $product->name,
            'price' => (float) $product->price,
        ]));
    }

    public function render()
    {
        $model = \App\Models\Product::active()->withTranslation()
            ->with(['specs' => fn ($q) => $q->withTranslation()->with(['attribute' => fn ($a) => $a->withTranslation()])])
            ->findOrFail($this->productId);

        $data = Catalog::productPage($model);

        $data['model'] = $model;

        $data['colors'] = array_map(fn ($c) => [
            'code' => $c['code'] ?? Str::slug($c['label'] ?? $c['name'] ?? ''),
            'label' => $c['label'] ?? $c['name'] ?? '',
            'hex' => $c['hex'] ?? null,
        ], $data['colors'] ?? []);

        $data['configs'] = array_map(fn ($i) => [
            'code' => $i['code'] ?? Str::slug($i['label'] ?? $i['name'] ?? ''),
            'label' => $i['label'] ?? $i['name'] ?? '',
            'note' => $i['note'] ?? null,
        ], $data['configs'] ?? ($data['config']['items'] ?? []));

        $data['bundleIds'] = $data['bundleIds'] ?? array_column($data['bundle'] ?? [], 'id');

        $name = trim($data['product']['brand'].' '.$data['product']['name']);

        return view('livewire.pages.product', $data)
            ->extends('layouts.app', [
                'page' => 'product',
                'nav' => 'catalog',
                'tab' => 'catalog',
                'pageCss' => 'product',
                'pageJs' => 'product',
                'seo' => [
                    // the admin may write its own meta; otherwise it is composed
                    'title' => $model->meta_title ?: $name.' — '.config('app.name'),
                    'description' => $model->meta_description
                        ?: ($data['product']['spec']
                            ?: $name.' — '.money($data['product']['price']).'. '.__('layout.description')),
                    'type' => 'product',
                    'image' => $data['product']['thumb'] ?? null,
                    'image_alt' => $name,
                    'price' => $data['product']['price'],
                    'availability' => Seo::availability($model),
                    // the slug is canonical; a variant or a stale slug redirects here
                    'canonical' => route('product', $model->slug),
                    'schema' => [
                        Seo::product($data['product'], $model),
                        Seo::breadcrumbs($data['product']['breadcrumbs']),
                    ],
                ],
            ])
            ->section('content');
    }
}
