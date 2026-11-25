<?php

use App\Http\Controllers\SalesChatController;
use App\Http\Controllers\SalesChatPresenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('sales-chat')->name('sales-chat.')->group(function (): void {
    Route::get('/', [SalesChatController::class, 'show'])->middleware('throttle:40,1,sales-chat-read')->name('show');
    Route::post('/message', [SalesChatController::class, 'message'])->middleware('throttle:10,1,sales-chat-write')->name('message');
    Route::post('/presence', [SalesChatPresenceController::class, 'heartbeat'])
        ->middleware('throttle:120,1,sales-chat-presence')->name('presence');
    Route::post('/lead', [SalesChatController::class, 'lead'])->middleware('throttle:5,1,sales-chat-lead')->name('lead');
});
