<?php

declare(strict_types=1);

use Core\Wallet\Presentation\Http\Controllers\ListPartyWalletsController;
use Core\Wallet\Presentation\Http\Controllers\ListWalletTransactionsController;
use Core\Wallet\Presentation\Http\Controllers\RecordWithdrawalController;
use Core\Wallet\Presentation\Http\Controllers\TransferFundsController;
use Core\Wallet\Presentation\Http\Controllers\WalletBalanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wallet routes — registered by WalletServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| ADR-0065 (replacing ADR-0033 §1): the only public mutations are zero-sum
| transfers and recorded withdrawals — never a free credit. Both carry the
| `idempotency` middleware (G7). Every route is scoped to the project AND
| the environment of the calling key.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/wallets/{walletId}/balance', WalletBalanceController::class)
            ->whereUuid('walletId')
            ->name('wallet.balance');
        Route::get('/wallets/{walletId}/transactions', ListWalletTransactionsController::class)
            ->whereUuid('walletId')
            ->name('wallet.transactions');
        Route::post('/wallets/transfers', TransferFundsController::class)
            ->middleware('idempotency')
            ->name('wallet.transfers');
        Route::post('/wallets/withdrawals', RecordWithdrawalController::class)
            ->middleware('idempotency')
            ->name('wallet.withdrawals');
        Route::get('/parties/{externalRef}/wallets', ListPartyWalletsController::class)
            ->where('externalRef', '[^/]+')
            ->name('wallet.party-wallets');
    });
