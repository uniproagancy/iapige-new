<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
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
    }

    public function render()
    {
        $model = \App\Models\Product::active()->withTranslation()
            ->with(['specs' => fn ($q) => $q->withTranslation()->with(['attribute' => fn ($a) => $a->withTranslation()])])
            ->findOrFail($this->productId);

        $data = Catalog::productPage($model);

        $data['model'] = $model;

        $data['colors'] = array_map(fn ($c) => [
            'code'  => $c['code'] ?? \Illuminate\Support\Str::slug($c['label'] ?? $c['name'] ?? ''),
            'label' => $c['label'] ?? $c['name'] ?? '',
            'hex'   => $c['hex'] ?? null,
        ], $data['colors'] ?? []);

        $data['configs'] = array_map(fn ($i) => [
            'code'  => $i['code'] ?? \Illuminate\Support\Str::slug($i['label'] ?? $i['name'] ?? ''),
            'label' => $i['label'] ?? $i['name'] ?? '',
            'note'  => $i['note'] ?? null,
        ], $data['configs'] ?? ($data['config']['items'] ?? []));

        $data['bundleIds'] = $data['bundleIds'] ?? array_column($data['bundle'] ?? [], 'id');

        return view('livewire.pages.product', $data)
            ->extends('layouts.app', [
                'page'        => 'product',
                'nav'         => 'catalog',
                'tab'         => 'catalog',
                'pageCss'     => 'product',
                'pageJs'      => 'product',
                'title'       => $data['product']['brand'].' '.$data['product']['name'].' — ELIO',
                'description' => $data['product']['brand'].' '.$data['product']['name'].' — '
                    .money($data['product']['price']).'. '.__('layout.description'),
            ])
            ->section('content');
    }
}