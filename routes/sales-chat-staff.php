<?php

use App\Http\Controllers\Admin\SalesChatSetupController;
use App\Http\Controllers\SalesChat\KnowledgeController;
use App\Http\Controllers\SalesChat\PresenceController;
use App\Http\Controllers\SalesChat\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('sales-chat')
    ->name('sales-chat.')
    ->group(function () {
        Route::prefix('setup')->name('setup.')->middleware('can:owner')->group(function (): void {
            Route::get('/', [SalesChatSetupController::class, 'show'])->name('show');
            Route::post('/activate', [SalesChatSetupController::class, 'activate'])->middleware('throttle:6,1')->name('activate');
            Route::post('/disable', [SalesChatSetupController::class, 'disable'])->name('disable');
        });

        Route::prefix('knowledge')->name('knowledge.')->group(function (): void {
            Route::get('/', [KnowledgeController::class, 'show'])->name('show');
            Route::post('/', [KnowledgeController::class, 'update'])->name('update');
        });

        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::get('/visitors', [PresenceController::class, 'show'])->name('presence.show');
        Route::get('/visitors/live', [PresenceController::class, 'data'])
            ->middleware('throttle:60,1')->name('presence.data');
        Route::get('/leads', [StaffController::class, 'leads'])->name('leads');
        Route::get('/queue', [StaffController::class, 'queue'])->middleware('throttle:60,1')->name('queue');
        Route::get('/{conversation}/messages', [StaffController::class, 'messages'])
            ->whereNumber('conversation')->middleware('throttle:120,1')->name('messages');
        Route::post('/{conversation}/read', [StaffController::class, 'markRead'])
            ->whereNumber('conversation')->middleware('throttle:120,1')->name('read');
        Route::post('/{conversation}/claim', [StaffController::class, 'claim'])
            ->whereNumber('conversation')->middleware('throttle:30,1')->name('claim');
        Route::post('/{conversation}/called', [StaffController::class, 'markCalled'])
            ->whereNumber('conversation')->middleware('throttle:30,1')->name('called');
        Route::post('/{conversation}/reply', [StaffController::class, 'reply'])
            ->whereNumber('conversation')->middleware('throttle:60,1')->name('reply');
    });
