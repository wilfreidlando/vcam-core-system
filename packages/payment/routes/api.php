<?php

declare(strict_types=1);

use Core\Payment\Presentation\Http\Controllers\GetPaymentStatusController;
use Core\Payment\Presentation\Http\Controllers\StartPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment routes — registered by PaymentServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| The one public surface ADR-0038 decides: a canonical `channel`, never
| a provider name. `POST /v1/payments` carries the `idempotency`
| middleware (G7, ADR-0025) — the first route in the Core to activate
| it, since it's the first public route that mutates money.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/payments', StartPaymentController::class)
            ->middleware('idempotency')
            ->name('payments.start');
        Route::get('/payments/{paymentId}', GetPaymentStatusController::class)->name('payments.status');
    });
