<?php

namespace App\Providers;

use App\Jobs\ImportProductJob;
use App\Models\Language;
use App\Models\Order;
use App\Models\User;
use App\Services\Facebook\Pixel;
use App\Services\Import\ImportManager;
use App\Services\Import\TaxonomyResolver;
use App\Support\Translation\DatabaseTranslationLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Mcamara\LaravelLocalization\LaravelLocalization;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // interface strings: lang/*.php first, then rows from ui_translations on top
        $this->app->extend('translation.loader', fn (Loader $files) => new DatabaseTranslationLoader($files));

        // one per request, so its "already sent" list lasts exactly that long
        $this->app->singleton(Pixel::class);

        // one per worker, so a feed's repeated names are resolved once per run
        $this->app->singleton(TaxonomyResolver::class);

        // likewise the drivers, which would otherwise be rebuilt once per product
        $this->app->singleton(ImportManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineGates();
        $this->useBootstrapPagination();
        $this->defineRateLimiters();
        $this->useDatabaseLanguages();
    }

    /**
     * The admin is a Bootstrap theme; Laravel ships Tailwind markup.
     *
     * Nothing said so, so every paginated admin list rendered two bare white
     * boxes in the middle of a dark page — and, with no lang/pagination file
     * to read, the words on them were the translation keys themselves.
     *
     * Safe to set globally: the storefront pages through a "show more"
     * button and never calls links().
     */
    protected function useBootstrapPagination(): void
    {
        Paginator::useBootstrapFive();

        /*
         * Livewire does not read Laravel's setting. It picks its own view from
         * livewire.pagination_theme, which defaults to tailwind — and every
         * list in the admin is a Livewire component, so the line above on its
         * own changed nothing at all.
         */
        config(['livewire.pagination_theme' => 'bootstrap']);
    }

    /**
     * The pace the import keeps.
     *
     * ImportProductJob asks for RateLimited('import') and an undefined limiter
     * is silently a no-op, so until this existed the middleware did nothing at
     * all. Limited per supplier rather than globally: one source throttling us
     * is no reason to slow down the other eight.
     */
    protected function defineRateLimiters(): void
    {
        RateLimiter::for('import', fn (ImportProductJob $job) => Limit::perMinute(
            max(1, (int) config('shop.import_rate', 60))
        )->by('import:'.$job->supplierId));
    }

    /**
     * Who may see what.
     *
     * The order gate is shared by the thank-you page and the printable invoice:
     * both name a person and both hang off a number short enough to guess, so
     * the rule has to live in one place rather than in two copies that drift.
     */
    protected function defineGates(): void
    {
        Gate::define('admin', fn (User $user) => (bool) $user->is_admin);

        Gate::define('view-order', function (?User $user, Order $order) {
            if ($user && ((int) $order->user_id === (int) $user->id || $user->is_admin)) {
                return true;
            }

            // a guest sees the order they placed themselves, and no other
            return in_array($order->id, (array) session('placed_orders', []), true);
        });
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
            'app.locale' => $default,
            'app.fallback_locale' => $default,
            'laravellocalization.supportedLocales' => $supported,
            'laravellocalization.localesOrder' => array_keys($supported),
        ]);

        $this->app->setLocale($default);
        $this->app['translator']->setFallback($default);

        // if anything resolved the package before us, update the instance too
        if ($this->app->resolved(LaravelLocalization::class)) {
            $this->app->make(LaravelLocalization::class)->setSupportedLocales($supported);
        }
    }
}
