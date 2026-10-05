<?php

namespace App\Providers;

use App\Models\Language;
use App\Support\Translation\DatabaseTranslationLoader;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;
use Mcamara\LaravelLocalization\LaravelLocalization;
use Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // interface strings: lang/*.php first, then rows from ui_translations on top
        $this->app->extend('translation.loader', fn (Loader $files) => new DatabaseTranslationLoader($files));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
		Gate::define('admin', fn (\App\Models\User $user) => $user->is_admin);
        $this->useDatabaseLanguages();
    }

    /**
     * Feed the active rows of `languages` to mcamara/laravel-localization.
     *
     * Runs in boot(), before routes are loaded — the package reads app.locale
     * in its constructor and routes call LaravelLocalization::setLocale().
     * With no rows yet (fresh install) config/laravellocalization.php is used.
     */
    protected function useDatabaseLanguages(): void
    {
        $supported = Language::supportedLocales();

        if (! $supported) {
            return;
        }

        $default = Language::defaultCode();

        config([
            'app.locale'                           => $default,
            'app.fallback_locale'                  => $default,
            'laravellocalization.supportedLocales' => $supported,
            'laravellocalization.localesOrder'     => array_keys($supported),
        ]);

        $this->app->setLocale($default);
        $this->app['translator']->setFallback($default);

        // if anything resolved the package before us, update the instance too
        if ($this->app->resolved(LaravelLocalization::class)) {
            $this->app->make(LaravelLocalization::class)->setSupportedLocales($supported);
        }
    }
}
