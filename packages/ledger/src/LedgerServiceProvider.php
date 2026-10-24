<?php

declare(strict_types=1);

namespace Core\Ledger;

use Core\Ledger\Application\UseCase\IsSupportedCurrencyUseCase;
use Core\Ledger\Application\UseCase\ListAccountEntriesUseCase;
use Core\Ledger\Application\UseCase\PostLedgerTransaction;
use Core\Ledger\Application\UseCase\ResolveOrCreateAccount;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\ListAccountEntries;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\TrialBalance;
use Core\Ledger\Domain\Port\CurrencyRepository;
use Core\Ledger\Domain\Port\LedgerAccountRepository;
use Core\Ledger\Domain\Port\LedgerEntryRepository;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentCurrencyRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerAccountRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerEntryRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerTransactionRepository;
use Core\Ledger\Infrastructure\Persistence\Query\DatabaseLedgerReadModel;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: ledger
 * Responsibility: Immutable double-entry ledger, accounts, materialized
 * balances. The financial core: depends on no other module.
 * Delivery lot: Lot 2
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LedgerAccountRepository::class, EloquentLedgerAccountRepository::class);
        $this->app->bind(LedgerTransactionRepository::class, EloquentLedgerTransactionRepository::class);
        $this->app->bind(AccountResolver::class, ResolveOrCreateAccount::class);
        $this->app->bind(LedgerPoster::class, PostLedgerTransaction::class);
        $this->app->bind(CurrencyRepository::class, EloquentCurrencyRepository::class);
        $this->app->bind(IsSupportedCurrency::class, IsSupportedCurrencyUseCase::class);
        $this->app->bind(LedgerEntryRepository::class, EloquentLedgerEntryRepository::class);
        $this->app->bind(ListAccountEntries::class, ListAccountEntriesUseCase::class);
        // ADR-0076 §4 — the read side: one read model behind three contracts.
        $this->app->singleton(DatabaseLedgerReadModel::class);
        $this->app->bind(ListLedgerTransactions::class, DatabaseLedgerReadModel::class);
        $this->app->bind(TrialBalance::class, DatabaseLedgerReadModel::class);
        $this->app->bind(FindPostedLedgerKeys::class, DatabaseLedgerReadModel::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
