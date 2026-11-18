<?php

use App\Http\Middleware\TrackPageView;
use App\Livewire\Admin\Procurement\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin', 'can:owner'])
    ->withoutMiddleware([TrackPageView::class])
    ->prefix('admin/procurement')->name('admin.procurement.')->group(function (): void {
        Route::get('/', Index::class)->name('index');
    });
