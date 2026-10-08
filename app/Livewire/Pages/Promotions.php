<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
use App\Support\Seo;
use Livewire\Component;

/**
 * /promotions — every campaign running right now.
 *
 * Without this the front page's single rail is the only way in, so the
 * second campaign and everything after it can be reached only by somebody
 * who already knows its address.
 */
class Promotions extends Component
{
    public function render()
    {
        $campaigns = Catalog::campaigns();

        return view('livewire.pages.promotions', ['campaigns' => $campaigns])
            ->extends('layouts.app', [
                'page' => 'promotions',
                'nav' => 'catalog',
                'tab' => 'catalog',
                'pageCss' => 'catalog',
                'seo' => [
                    'title' => __('promo.all').' — '.config('app.name'),
                    'description' => __('promo.all_lead'),
                    'canonical' => route('promotions'),
                    'schema' => [
                        Seo::breadcrumbs([['name' => __('promo.all'), 'url' => route('promotions')]]),
                    ],
                ],
            ])->section('content');
    }
}
