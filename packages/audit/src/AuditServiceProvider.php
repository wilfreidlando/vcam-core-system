<?php

declare(strict_types=1);

namespace Core\Audit;

use Core\Audit\Contracts\AuditDigests;
use Core\Audit\Contracts\AuditLogger;
use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use Core\Audit\Infrastructure\Persistence\Eloquent\EloquentAuditQuery;
use Core\Audit\Infrastructure\Persistence\Query\DatabaseAuditDigests;
use Core\Audit\Presentation\Console\Commands\SealAuditLogCommand;
use Core\Audit\Presentation\Console\Commands\VerifyAuditLogCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: audit
 * Responsibility: Append-only audit journal of sensitive operations, correlated end to end.
 * Delivery lot: Lot 1 (write side), Lot 6 (read side, ADR-0044), Lot 8 (tamper evidence, ADR-0081 S-16)
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditLogger::class, EloquentAuditLogger::class);
        $this->app->bind(AuditQuery::class, EloquentAuditQuery::class);
        $this->app->bind(AuditDigests::class, DatabaseAuditDigests::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SealAuditLogCommand::class,
                VerifyAuditLogCommand::class,
            ]);
        }
    }
}
