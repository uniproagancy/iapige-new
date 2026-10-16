<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
use App\Support\Seo;
use Livewire\Component;

class Promotion extends Component
{
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render()
    {

        $campaign = Catalog::campaignPage(Catalog::campaign($this->slug));

        return view('livewire.pages.promotion', ['campaign' => $campaign])
            ->extends('layouts.app', [
                'page' => 'promotion',
                'nav' => 'catalog',
                'tab' => 'catalog',
                'pageCss' => 'catalog',
                'seo' => $this->seo($campaign),
            ])->section('content');
    }

    protected function seo(array $campaign): array
    {
        return [
            'title' => $campaign['title'].' — '.config('app.name'),
            'description' => $campaign['subtitle'] ?: __('promo.meta', ['name' => $campaign['title']]),
            'canonical' => route('promotion', $campaign['slug']),

            'robots' => 'noindex, follow',
            'schema' => [
                Seo::breadcrumbs([
                    ['name' => __('promo.all'), 'url' => route('promotions')],
                    ['name' => $campaign['title'], 'url' => route('promotion', $campaign['slug'])],
                ]),
                Seo::itemList($campaign['products'], $campaign['title']),
            ],
        ];
    }
}
