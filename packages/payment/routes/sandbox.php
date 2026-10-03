<?php

declare(strict_types=1);

use Core\Payment\Presentation\Http\Controllers\SandboxCheckoutController;
use Illuminate\Support\Facades\Route;

/*
| ADR-0060 — hosted sandbox checkout for simulated paylinks. No session, no
| API key: the expiring signed URL is the credential.
*/

Route::middleware('signed')->prefix('sandbox/checkout')->group(function (): void {
    Route::get('{transactionRef}', [SandboxCheckoutController::class, 'show'])
        ->where('transactionRef', 'sbx_[a-z0-9]+')
        ->name('sandbox.checkout.show');
    Route::post('{transactionRef}', [SandboxCheckoutController::class, 'complete'])
        ->where('transactionRef', 'sbx_[a-z0-9]+')
        ->name('sandbox.checkout.complete');
});
