<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
use App\Support\Seo;
use Livewire\Component;

/**
 * /promotions/{code} — one campaign and everything in it.
 *
 * The front page carries a single rail, which is enough while there is one
 * campaign and useless once there are several: the rest had nowhere to live.
 * Each campaign now has an address of its own, which is also what a banner,
 * a newsletter or an advert can point at.
 */
class Promotion extends Component
{
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render()
    {
        // a campaign that has ended or not yet started is a 404, not an empty page
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

    /** @param  array<string, mixed>  $campaign */
    protected function seo(array $campaign): array
    {
        return [
            'title' => $campaign['title'].' — '.config('app.name'),
            'description' => $campaign['subtitle'] ?: __('promo.meta', ['name' => $campaign['title']]),
            'canonical' => route('promotion', $campaign['slug']),
            /*
             * A campaign is a temporary page whose products also live in their
             * own categories, so it is followed but not indexed — otherwise it
             * competes with the pages that remain after it ends.
             */
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
