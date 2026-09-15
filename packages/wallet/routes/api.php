<?php

declare(strict_types=1);

use Core\Wallet\Presentation\Http\Controllers\WalletBalanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wallet routes — registered by WalletServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| Read-only, deliberately: ADR-0033 §1 keeps every mutation off the public
| HTTP surface for Lot 2.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/wallets/{walletId}/balance', WalletBalanceController::class)
            ->name('wallet.balance');
    });
