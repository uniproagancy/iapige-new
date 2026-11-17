<?php

use App\Http\Controllers\PublicWarrantyController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WarrantyServiceTemplateController;
use App\Http\Middleware\EnsureWarrantyEnabled;
use App\Http\Middleware\TrackPageView;
use App\Livewire\Admin\Warranties;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin', EnsureWarrantyEnabled::class])
    ->withoutMiddleware([TrackPageView::class])
    ->prefix('admin/warranties')->name('admin.warranties.')->group(function (): void {
        Route::get('/', Warranties\Index::class)->name('index');
        Route::get('/services', Warranties\Services::class)->name('services');
        Route::get('/services/{supplierCode}/{routeKey}/template', WarrantyServiceTemplateController::class)
            ->where('supplierCode', '[a-z0-9-]+')->where('routeKey', '[A-Za-z0-9._:-]+')->name('service-template');
        Route::get('/policies', Warranties\Policies::class)->middleware('can:owner')->name('policies');
        Route::get('/documents/{document}', [WarrantyController::class, 'document'])->whereNumber('document')->name('document');
        Route::get('/{certificate}', Warranties\Show::class)->whereNumber('certificate')->name('show');
        Route::get('/{certificate}/preview', [WarrantyController::class, 'preview'])->whereNumber('certificate')->name('preview');
        Route::get('/{certificate}/print', [WarrantyController::class, 'print'])->whereNumber('certificate')->name('print');
        Route::get('/{certificate}/pdf', [WarrantyController::class, 'pdf'])->whereNumber('certificate')->name('pdf');
        Route::post('/{certificate}/sms', [WarrantyController::class, 'sms'])->whereNumber('certificate')
            ->middleware('throttle:5,1')->name('sms');
    });

Route::middleware([EnsureWarrantyEnabled::class.':public', 'throttle:60,1'])
    ->withoutMiddleware([TrackPageView::class])
    ->prefix('warranty')->name('warranty.public.')->group(function (): void {
        Route::get('/{token}', [PublicWarrantyController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('show');
        Route::get('/{token}/pdf', [PublicWarrantyController::class, 'pdf'])->where('token', '[a-f0-9]{64}')->name('pdf');
        Route::get('/{token}/documents/{document}', [PublicWarrantyController::class, 'document'])
            ->where('token', '[a-f0-9]{64}')->whereNumber('document')->name('document');
    });
