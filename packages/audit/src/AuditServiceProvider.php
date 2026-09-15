<?php

declare(strict_types=1);

namespace Core\Audit;

use Core\Audit\Contracts\AuditLogger;
use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use Core\Audit\Infrastructure\Persistence\Eloquent\EloquentAuditQuery;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: audit
 * Responsibility: Append-only audit journal of sensitive operations, correlated end to end.
 * Delivery lot: Lot 1 (write side), Lot 6 (read side, ADR-0044)
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditLogger::class, EloquentAuditLogger::class);
        $this->app->bind(AuditQuery::class, EloquentAuditQuery::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
