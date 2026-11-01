<?php

declare(strict_types=1);

namespace Core\Ledger;

use Core\Ledger\Application\UseCase\IsSupportedCurrencyUseCase;
use Core\Ledger\Application\UseCase\LedgerAdjustmentsUseCase;
use Core\Ledger\Application\UseCase\ListAccountEntriesUseCase;
use Core\Ledger\Application\UseCase\ListLedgerAdjustmentsUseCase;
use Core\Ledger\Application\UseCase\PostLedgerTransaction;
use Core\Ledger\Application\UseCase\ResolveOrCreateAccount;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\AccountStatement;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Contracts\LedgerAdjustments;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\ListAccountEntries;
use Core\Ledger\Contracts\ListLedgerAdjustments;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\TrialBalance;
use Core\Ledger\Domain\Port\CurrencyRepository;
use Core\Ledger\Domain\Port\LedgerAccountRepository;
use Core\Ledger\Domain\Port\LedgerAdjustmentRepository;
use Core\Ledger\Domain\Port\LedgerEntryRepository;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentCurrencyRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerAccountRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerAdjustmentRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerEntryRepository;
use Core\Ledger\Infrastructure\Persistence\Eloquent\EloquentLedgerTransactionRepository;
use Core\Ledger\Infrastructure\Persistence\Query\DatabaseLedgerReadModel;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Illuminate\Contracts\Foundation\Application;
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
        // ADR-0076 §4 — the read side: one read model behind four contracts.
        $this->app->singleton(DatabaseLedgerReadModel::class);
        $this->app->bind(ListLedgerTransactions::class, DatabaseLedgerReadModel::class);
        $this->app->bind(TrialBalance::class, DatabaseLedgerReadModel::class);
        $this->app->bind(FindPostedLedgerKeys::class, DatabaseLedgerReadModel::class);
        $this->app->bind(AccountStatement::class, DatabaseLedgerReadModel::class);

        // ADR-0076 §6 — the operating corrections: proposed in the
        // console, validated by a second pair of eyes, then booked as a
        // normal ledger transaction.
        $this->app->bind(LedgerAdjustmentRepository::class, EloquentLedgerAdjustmentRepository::class);
        $this->app->bind(ListLedgerAdjustments::class, ListLedgerAdjustmentsUseCase::class);
        $this->app->bind(LedgerAdjustments::class, function (Application $app): LedgerAdjustmentsUseCase {
            /** @var int $threshold */
            $threshold = $app->make('config')->get('ledger.adjustments.approval_threshold_minor', 0);

            return new LedgerAdjustmentsUseCase(
                $app->make(UnitOfWork::class),
                $app->make(LedgerAdjustmentRepository::class),
                $app->make(LedgerTransactionRepository::class),
                $app->make(AccountResolver::class),
                $app->make(LedgerPoster::class),
                $app->make(Clock::class),
                $app->make(Identifier::class),
                $threshold,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
