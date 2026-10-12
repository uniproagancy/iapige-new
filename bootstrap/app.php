<?php

use App\Http\Middleware\TrackPageView;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // mcamara/laravel-localization
        $middleware->alias([
            'localize' => LaravelLocalizationRoutes::class,
            'localizationRedirect' => LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => LocaleSessionRedirect::class,
            'localeCookieRedirect' => LocaleCookieRedirect::class,
            'localeViewPath' => LaravelLocalizationViewPath::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('home'));

        /*
         * The consent cookie is written by the browser, so it must not be
         * encrypted.
         *
         * The cookie banner sets it from JavaScript, which produces a plain
         * value. EncryptCookies then failed to decrypt it and dropped it from
         * the request altogether, so Pixel::consented() read null for every
         * visitor and the server half of the pixel sent nothing at all — while
         * the browser half, reading document.cookie directly, worked fine. The
         * two halves disagreed about whether consent had been given.
         *
         * Nothing secret lives in it: it holds "all" or "necessary".
         */

        $middleware->encryptCookies(except: ['cookie_consent']);

        // the server half of the Meta pixel; it mints the id the browser reuses
        $middleware->web(append: [TrackPageView::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
