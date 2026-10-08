<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Defaults for the social preview tags.
 *
 * Every page gets them, and the few that have something better to say — a
 * product, a category — override them in their own view.
 */
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
