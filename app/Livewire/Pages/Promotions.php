<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
use App\Support\Seo;
use Livewire\Component;

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
