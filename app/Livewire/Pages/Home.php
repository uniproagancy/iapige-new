<?php

namespace App\Livewire\Pages;

use App\Support\Catalog;
use App\Support\Store;
use Livewire\Component;

class Home extends Component
{
    public function render()
    {
        return view('livewire.pages.home', [
            'slides'   => Store::slides(),
            'deals'    => Catalog::deals(),
            'sections' => Catalog::sections(),
            'brands'   => Catalog::brands(),
        ])
            ->extends('layouts.app', [
                'page'   => 'home',
                'nav'    => 'home',
                'tab'    => 'home',
                'pageJs' => 'home',
            ])
            ->section('content');
    }
}