<?php

use App\Http\Controllers\CourierWarrantyController;
use App\Http\Middleware\EnsureCourierWarrantyAccess;
use App\Http\Middleware\TrackPageView;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureCourierWarrantyAccess::class])
    ->withoutMiddleware([TrackPageView::class])
    ->prefix('courier/deliveries')->name('courier.warranties.')->group(function (): void {
        Route::get('/{delivery}/warranties', [CourierWarrantyController::class, 'index'])
            ->whereNumber('delivery')->name('index');
        Route::get('/{delivery}/warranties/{certificate}/print', [CourierWarrantyController::class, 'print'])
            ->whereNumber('delivery')->whereNumber('certificate')->name('print');
        Route::post('/{delivery}/warranties/{certificate}/sms', [CourierWarrantyController::class, 'sms'])
            ->whereNumber('delivery')->whereNumber('certificate')->middleware('throttle:5,1')->name('sms');
        Route::post('/{delivery}/warranties/{certificate}/serial', [CourierWarrantyController::class, 'serial'])
            ->whereNumber('delivery')->whereNumber('certificate')->middleware('throttle:10,1')->name('serial');
    });
