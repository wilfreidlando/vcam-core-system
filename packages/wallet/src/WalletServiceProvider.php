<?php

declare(strict_types=1);

namespace Core\Wallet;

use Core\Wallet\Application\UseCase\CreditWalletUseCase;
use Core\Wallet\Application\UseCase\DebitWalletUseCase;
use Core\Wallet\Application\UseCase\GetWalletBalanceUseCase;
use Core\Wallet\Application\UseCase\ListPartyWalletsUseCase;
use Core\Wallet\Application\UseCase\ListWalletsUseCase;
use Core\Wallet\Application\UseCase\ListWalletTransactionsUseCase;
use Core\Wallet\Application\UseCase\RecordWithdrawalUseCase;
use Core\Wallet\Application\UseCase\TransferFundsUseCase;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\GetWalletBalance;
use Core\Wallet\Contracts\ListPartyWallets;
use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletTransactions;
use Core\Wallet\Contracts\RecordWithdrawal;
use Core\Wallet\Contracts\TransferFunds;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletOperationRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Core\Wallet\Infrastructure\Persistence\Eloquent\EloquentWalletBalanceRepository;
use Core\Wallet\Infrastructure\Persistence\Eloquent\EloquentWalletOperationRepository;
use Core\Wallet\Infrastructure\Persistence\Eloquent\EloquentWalletRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: wallet
 * Responsibility: Wallets — deposits, withdrawals, transfers — backed by
 * the Ledger. Public mutations are zero-sum transfers and recorded
 * withdrawals only, never a free credit (ADR-0065, replacing ADR-0033 §1).
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
        $this->app->bind(ListWallets::class, ListWalletsUseCase::class);
        // ADR-0065 — transfers, recorded withdrawals and reads by party.
        $this->app->bind(WalletOperationRepository::class, EloquentWalletOperationRepository::class);
        $this->app->bind(TransferFunds::class, TransferFundsUseCase::class);
        $this->app->bind(RecordWithdrawal::class, RecordWithdrawalUseCase::class);
        $this->app->bind(ListPartyWallets::class, ListPartyWalletsUseCase::class);
        $this->app->bind(ListWalletTransactions::class, ListWalletTransactionsUseCase::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        // No ops CLI for credit/debit (security review finding — removed:
        // neither caller-side command verified wallet ownership, and
        // manual wallet adjustment has no real operational need today;
        // automatic crediting happens via HandlePaymentStatusChangedUseCase).
        // The one remaining command is test-infrastructure only, never
        // registered outside the testing environment.
        if ($this->app->runningInConsole() && $this->app->environment('testing')) {
            $this->commands([
                Presentation\Console\Commands\DebitWalletForConcurrencyTestingCommand::class,
                Presentation\Console\Commands\TransferFundsForConcurrencyTestingCommand::class,
            ]);
        }
    }
}
