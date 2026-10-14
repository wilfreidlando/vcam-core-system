<?php

declare(strict_types=1);

use Core\Payment\Presentation\Http\Controllers\SimulatorCheckoutController;
use Illuminate\Support\Facades\Route;

/*
| ADR-0066 — hosted checkout for simulated paylinks (deployments with the
| payment simulator enabled). No session, no API key: the expiring signed
| URL is the credential.
*/

Route::middleware('signed')->prefix('simulator/checkout')->group(function (): void {
    Route::get('{transactionRef}', [SimulatorCheckoutController::class, 'show'])
        ->where('transactionRef', 'sim_[a-z0-9]+')
        ->name('simulator.checkout.show');
    Route::post('{transactionRef}', [SimulatorCheckoutController::class, 'complete'])
        ->where('transactionRef', 'sim_[a-z0-9]+')
        ->name('simulator.checkout.complete');
});
