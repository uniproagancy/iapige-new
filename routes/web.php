<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use App\Livewire\Admin;

Route::group([
    'prefix'     => LaravelLocalization::setLocale(),
    'middleware' => ['localeSessionRedirect', 'localizationRedirect'],
], function () {

    // Livewire requests keep the page's language: /en/livewire-…/update
    Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle));

	Route::get('/', App\Livewire\Pages\Home::class)->name('home');
	
	Route::get('/catalog/{slug?}', App\Livewire\Pages\Catalog::class)->name('catalog');
	Route::get('/product/{slug}', App\Livewire\Pages\Product::class)->name('product');
	Route::get('/checkout', App\Livewire\Pages\Checkout::class)->name('checkout');
    Route::get('/order/{number}', App\Livewire\Pages\OrderPlaced::class)->name('order');

    Route::get('/contact', [PageController::class, 'contact'])->name('contact');
    Route::get('/about', [PageController::class, 'about'])->name('about');
    Route::get('/info/{doc?}', [PageController::class, 'info'])->name('info');
    
	Route::get('/invoice/{number}', [InvoiceController::class, 'show'])->name('invoice');

    Route::middleware('auth')->group(function () {
        Route::get('/account', App\Livewire\Account\Index::class)->name('account');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware('guest')
        ->get('/password/reset/{token}', App\Livewire\Auth\ResetPassword::class)
        ->name('password.reset');

});

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

Route::post('/payment/callback/{driver}', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
Route::get('/payment/return/{number}', [PaymentController::class, 'return'])
    ->name('payment.return');
Route::post('/payment/bog/installment/{number}', [\App\Http\Controllers\BogInstallmentController::class, 'create'])
    ->name('payment.bog.installment');
Route::get('/payment/credo/{number}', [\App\Http\Controllers\CredoController::class, 'form'])
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
		
		Route::get('/callbacks', Admin\Callbacks\Index::class)->name('callbacks');
		Route::get('/users', Admin\Users\Index::class)->name('users');
		Route::get('/users/{user}', Admin\Users\Show::class)->name('users.show');
		
});
