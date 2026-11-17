<?php

use App\Http\Controllers\WarrantyAgentController;
use App\Http\Controllers\WarrantyAgentKeyController;
use App\Http\Middleware\AuthenticateWarrantyAgent;
use App\Http\Middleware\EnsureWarrantyEnabled;
use App\Http\Middleware\TrackPageView;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:owner', EnsureWarrantyEnabled::class.':agent', EnsureWarrantyEnabled::class.':owner'])
    ->withoutMiddleware(TrackPageView::class)->prefix('admin/warranties/agent-keys')->name('admin.warranties.agent-keys')
    ->group(function (): void {
        Route::get('/', [WarrantyAgentKeyController::class, 'index']);
        Route::post('/', [WarrantyAgentKeyController::class, 'store'])->middleware('throttle:6,1')->name('.store');
        Route::post('/{key}/revoke', [WarrantyAgentKeyController::class, 'revoke'])->middleware('throttle:6,1')->name('.revoke');
    });

Route::prefix('api/private/warranties')->name('warranty.agent.')
    ->middleware([EnsureWarrantyEnabled::class.':agent', 'throttle:30,1'])
    ->withoutMiddleware([TrackPageView::class, ValidateCsrfToken::class, VerifyCsrfToken::class])
    ->group(function (): void {
        Route::get('/policies', [WarrantyAgentController::class, 'policies'])
            ->middleware(AuthenticateWarrantyAgent::class.':read')->name('policies');
        Route::get('/certificates/{certificate}', [WarrantyAgentController::class, 'show'])
            ->middleware(AuthenticateWarrantyAgent::class.':read')->name('show');
        Route::post('/certificates/prepare', [WarrantyAgentController::class, 'prepare'])
            ->middleware(AuthenticateWarrantyAgent::class.':prepare')->name('prepare');
        Route::post('/certificates/{certificate}/draft', [WarrantyAgentController::class, 'update'])
            ->middleware(AuthenticateWarrantyAgent::class.':prepare')->name('update');
        Route::post('/certificates/{certificate}/issue', [WarrantyAgentController::class, 'issue'])
            ->middleware(AuthenticateWarrantyAgent::class.':issue')->name('issue');
        Route::post('/certificates/{certificate}/sms', [WarrantyAgentController::class, 'send'])
            ->middleware(AuthenticateWarrantyAgent::class.':send')->name('send');
    });
