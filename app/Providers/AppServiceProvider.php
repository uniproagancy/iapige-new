<?php

namespace App\Providers;

use App\Jobs\ImportProductJob;
use App\Models\Language;
use App\Models\Order;
use App\Models\Supplier;
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
    public function register(): void
    {

        $this->app->extend('translation.loader', fn (Loader $files) => new DatabaseTranslationLoader($files));

        $this->app->singleton(Pixel::class);

        $this->app->singleton(TaxonomyResolver::class);

        $this->app->singleton(ImportManager::class);
    }

    public function boot(): void
    {
        $this->defineGates();
        $this->useBootstrapPagination();
        $this->defineRateLimiters();
        $this->useDatabaseLanguages();
    }

    protected function useBootstrapPagination(): void
    {
        Paginator::useBootstrapFive();

        config(['livewire.pagination_theme' => 'bootstrap']);
    }

    protected function defineRateLimiters(): void
    {

        RateLimiter::for('import', function (ImportProductJob $job) {
            static $rates = [];

            $rates[$job->supplierId] ??= (function () use ($job): int {
                try {
                    $own = Supplier::find($job->supplierId)?->config['rate'] ?? null;
                } catch (\Throwable) {
                    $own = null;
                }

                return (int) ($own ?? config('shop.import_rate', 60));
            })();

            return Limit::perMinute(max(1, $rates[$job->supplierId]))
                ->by('import:'.$job->supplierId);
        });
    }

    protected function defineGates(): void
    {
        Gate::define('admin', fn (User $user) => (bool) $user->is_admin);

        Gate::define('view-order', function (?User $user, Order $order) {
            if ($user && ((int) $order->user_id === (int) $user->id || $user->is_admin)) {
                return true;
            }

            return in_array($order->id, (array) session('placed_orders', []), true);
        });
    }

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

        if ($this->app->resolved(LaravelLocalization::class)) {
            $this->app->make(LaravelLocalization::class)->setSupportedLocales($supported);
        }
    }
}
