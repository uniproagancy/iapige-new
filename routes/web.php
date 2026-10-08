<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BogInstallmentController;
use App\Http\Controllers\CredoController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SitemapController;
use App\Livewire\Account\Index;
use App\Livewire\Admin;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Pages\Catalog;
use App\Livewire\Pages\Checkout;
use App\Livewire\Pages\Home;
use App\Livewire\Pages\OrderPlaced;
use App\Livewire\Pages\Product;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localeSessionRedirect', 'localizationRedirect'],
], function () {

    // Livewire requests keep the page's language: /en/livewire-…/update
    Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle));

    Route::get('/', Home::class)->name('home');

    Route::get('/catalog/{slug?}', Catalog::class)->name('catalog');
    Route::get('/product/{slug}', Product::class)->name('product');
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

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

/*
 | The Facebook / Instagram catalogue.
 |
 | Outside the {locale} group on purpose: Facebook stores the one URL it was
 | given and refetches it for years, so a locale prefix would freeze the feed
 | to whichever language happened to be active when it was pasted in. The feed
 | declares its own locale in config/feeds.php.
 |
 | The file is built by the scheduler, never by this request.
 */
Route::get('/feed/facebook.xml', [FeedController::class, 'facebook'])->name('feed.facebook');

Route::post('/payment/callback/{driver}', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware([VerifyCsrfToken::class]);
Route::get('/payment/return/{number}', [PaymentController::class, 'return'])
    ->name('payment.return');
Route::post('/payment/bog/installment/{number}', [BogInstallmentController::class, 'create'])
    ->name('payment.bog.installment');
Route::get('/payment/credo/{number}', [CredoController::class, 'form'])
    ->name('payment.credo.form');

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

        Route::get('/mapping', Admin\Mapping\Index::class)->name('mapping');
        Route::get('/attributes', Admin\Attributes\Index::class)->name('attributes');

        Route::get('/orders', Admin\Orders\Index::class)->name('orders');
        Route::get('/orders/{order}', Admin\Orders\Show::class)->name('orders.show');

        Route::get('/promotions', Admin\Promotions\Index::class)->name('promotions');

        Route::get('/callbacks', Admin\Callbacks\Index::class)->name('callbacks');
        Route::get('/users', Admin\Users\Index::class)->name('users');
        Route::get('/users/{user}', Admin\Users\Show::class)->name('users.show');

    });
