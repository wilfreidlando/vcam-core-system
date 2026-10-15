<?php

declare(strict_types=1);

use Core\Payment\Presentation\Http\Controllers\GetPaymentStatusController;
use Core\Payment\Presentation\Http\Controllers\SimulatePaymentController;
use Core\Payment\Presentation\Http\Controllers\StartPayinPaymentController;
use Core\Payment\Presentation\Http\Controllers\StartPaylinkPaymentController;
use Core\Payment\Presentation\Http\Controllers\StartPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment routes — registered by PaymentServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| The public surface ADR-0038 decides: a canonical `channel`, never a
| provider name. `POST /v1/payments/paylink` and `POST /v1/payments/payin`
| (user request) are the two explicit entry points — a hosted payment
| link vs. a direct app-driven charge — both carrying the `idempotency`
| middleware (G7, ADR-0025). `POST /v1/payments` stays registered
| unversioned for backward compatibility (G9) but is no longer the
| documented entry point for new integrations.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/payments', StartPaymentController::class)
            ->middleware('idempotency')
            ->name('payments.start');
        Route::post('/payments/paylink', StartPaylinkPaymentController::class)
            ->middleware('idempotency')
            ->name('payments.start.paylink');
        Route::post('/payments/payin', StartPayinPaymentController::class)
            ->middleware('idempotency')
            ->name('payments.start.payin');
        // ADR-0066 — only where the payment simulator is enabled (staging).
        Route::post('/payments/{paymentId}/simulate', SimulatePaymentController::class)
            ->middleware('idempotency')
            ->whereUuid('paymentId')
            ->name('payments.simulate');
        Route::get('/payments/{paymentId}', GetPaymentStatusController::class)
            ->whereUuid('paymentId')
            ->name('payments.status');
    });
