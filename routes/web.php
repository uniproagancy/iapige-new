<?php

use App\Http\Controllers\AltaBrowserController;
use App\Http\Controllers\AltaStockFeedController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BogInstallmentController;
use App\Http\Controllers\CredoController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JsonImportFileController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Middleware\TrackPageView;
use App\Livewire\Account\Index;
use App\Livewire\Admin;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Pages\Catalog;
use App\Livewire\Pages\Checkout;
use App\Livewire\Pages\Home;
use App\Livewire\Pages\OrderPlaced;
use App\Livewire\Pages\PayOrder;
use App\Livewire\Pages\Product;
use App\Livewire\Pages\Promotion;
use App\Livewire\Pages\Promotions;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localeSessionRedirect', 'localizationRedirect'],
], function () {

    Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle));

    Route::get('/', Home::class)->name('home');

    Route::get('/catalog/{slug?}', Catalog::class)->name('catalog');
    Route::get('/product/{slug}', Product::class)->name('product');

    Route::get('/promotions', Promotions::class)->name('promotions');
    Route::get('/promotions/{slug}', Promotion::class)->name('promotion');
    Route::get('/checkout', Checkout::class)->name('checkout');
    Route::get('/order/{number}', OrderPlaced::class)->name('order');

    Route::get('/contact', [PageController::class, 'contact'])->name('contact');
    Route::get('/about', [PageController::class, 'about'])->name('about');
    Route::get('/info/{doc?}', [PageController::class, 'info'])->name('info');

    Route::get('/invoice/{number}', [InvoiceController::class, 'show'])->name('invoice');

    Route::middleware('auth')->group(function () {
        Route::get('/account', Index::class)->name('account');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware('guest')
        ->get('/password/reset/{token}', ResetPassword::class)
        ->name('password.reset');

});

Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('social.callback');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

Route::get('/feed/facebook.xml', [FeedController::class, 'facebook'])->name('feed.facebook');
Route::get('/feed/google.xml', [FeedController::class, 'google'])->name('feed.google');

Route::get('/feeds/alta-stock.json', AltaStockFeedController::class)
    ->middleware('throttle:12,1')
    ->withoutMiddleware([TrackPageView::class])
    ->name('feeds.alta-stock');

/* The scheduler does this every five minutes; the address is for hosting
   where only a plain cron fetch is available. */
Route::get('/cron/payments', [CronController::class, 'payments'])
    ->middleware('throttle:12,1')
    ->name('cron.payments');

Route::post('/payment/callback/{driver}', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware([VerifyCsrfToken::class]);
Route::get('/payment/return/{number}', [PaymentController::class, 'return'])
    ->name('payment.return');
Route::post('/payment/bog/installment/{number}', [BogInstallmentController::class, 'create'])
    ->name('payment.bog.installment');
Route::get('/payment/credo/{number}', [CredoController::class, 'form'])
    ->name('payment.credo.form');
Route::get('/payment/credo-split/{number}', [CredoController::class, 'split'])
    ->name('payment.credo_split.form');

Route::get('/pay/{number}', PayOrder::class)->name('order.pay');

Route::middleware(['auth', 'can:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', Admin\Dashboard::class)->name('dashboard');
        Route::get('/categories', Admin\Categories\Index::class)->name('categories');
        Route::get('/brands', Admin\Brands\Index::class)->name('brands');
        Route::get('/products', Admin\Products\Index::class)->name('products');
        Route::get('/products/create', Admin\Products\Form::class)->name('products.create');
        Route::get('/products/{product}/edit', Admin\Products\Form::class)->name('products.edit');

        Route::get('/import/upload', Admin\Import\Upload::class)->name('import.upload');
        Route::get('/import/json', Admin\Import\Json::class)->name('import.json');
        Route::get('/import/json-file/client', [JsonImportFileController::class, 'client'])->name('import.json-file.client');
        Route::post('/import/json-file/begin', [JsonImportFileController::class, 'begin'])->name('import.json-file.begin');
        Route::post('/import/json-file/{id}/chunk', [JsonImportFileController::class, 'chunk'])->whereUuid('id')->name('import.json-file.chunk');
        Route::post('/import/json-file/{id}/finish', [JsonImportFileController::class, 'finish'])->whereUuid('id')->name('import.json-file.finish');
        Route::get('/import/links', Admin\Import\Links::class)->name('import.links');
        Route::get('/import/run', Admin\Import\Run::class)->name('import.run');

        Route::get('/mapping', Admin\Mapping\Index::class)->name('mapping');
        Route::get('/attributes', Admin\Attributes\Index::class)->name('attributes');

        Route::get('/orders', Admin\Orders\Index::class)->name('orders');
        Route::get('/orders/create', Admin\Orders\Create::class)->name('orders.create');
        Route::get('/orders/{order}/edit', Admin\Orders\Edit::class)->name('orders.edit');
        Route::get('/orders/{order}/invoice', [InvoiceController::class, 'download'])->name('orders.invoice');
        Route::get('/orders/{order}/invoice/preview', [InvoiceController::class, 'preview'])->name('orders.invoice.preview');
        Route::get('/orders/{order}', Admin\Orders\Show::class)->name('orders.show');

        Route::get('/promotions', Admin\Promotions\Index::class)->name('promotions');
        Route::get('/slides', Admin\Slides\Index::class)->name('slides');

        Route::get('/callbacks', Admin\Callbacks\Index::class)->name('callbacks');

        /* The shop's own settings, its people and its suppliers stay with
           the owner; an operator works the orders and the catalogue. */
        Route::middleware('can:owner')->group(function () {
            Route::get('/alta-browser/feed', [AltaBrowserController::class, 'feed'])->middleware('throttle:6,1')->name('alta-browser.feed');
            Route::post('/alta-browser/product', [AltaBrowserController::class, 'store'])->middleware('throttle:30,1')->name('alta-browser.product');
            Route::get('/alta-browser/status', [AltaBrowserController::class, 'status'])->middleware('throttle:60,1')->name('alta-browser.status');
            Route::get('/suppliers', Admin\Suppliers\Index::class)->name('suppliers');
            Route::get('/settings', Admin\Settings\Index::class)->name('settings');
            Route::get('/users', Admin\Users\Index::class)->name('users');
            Route::get('/users/{user}', Admin\Users\Show::class)->name('users.show');
        });

    });
