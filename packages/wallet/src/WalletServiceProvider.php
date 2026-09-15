<?php

declare(strict_types=1);

namespace Core\Wallet;

use Core\Wallet\Application\UseCase\CreditWalletUseCase;
use Core\Wallet\Application\UseCase\DebitWalletUseCase;
use Core\Wallet\Application\UseCase\GetWalletBalanceUseCase;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\GetWalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Core\Wallet\Infrastructure\Persistence\Eloquent\EloquentWalletBalanceRepository;
use Core\Wallet\Infrastructure\Persistence\Eloquent\EloquentWalletRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: wallet
 * Responsibility: Wallets — deposits, withdrawals, holds — backed by the
 * Ledger. Every mutation is an internal Application use case (ADR-0033 §1);
 * only a read-only balance endpoint is public.
 * Delivery lot: Lot 2 (credit/debit — hold/capture/release not yet built)
 */
final class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);
        $this->app->bind(WalletBalanceRepository::class, EloquentWalletBalanceRepository::class);
        $this->app->bind(CreditWallet::class, CreditWalletUseCase::class);
        $this->app->bind(DebitWallet::class, DebitWalletUseCase::class);
        $this->app->bind(GetWalletBalance::class, GetWalletBalanceUseCase::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Presentation\Console\Commands\CreditWalletCommand::class,
                Presentation\Console\Commands\DebitWalletCommand::class,
            ]);
        }
    }
}
