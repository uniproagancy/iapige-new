<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            $view->with('seo', array_merge([
                'title' => __('layout.title'),
                'description' => __('layout.description'),
                'image' => asset('img/og-default.jpg'),
                'type' => 'website',
            ], (array) ($view->getData()['seo'] ?? [])));
        });
    }
}
