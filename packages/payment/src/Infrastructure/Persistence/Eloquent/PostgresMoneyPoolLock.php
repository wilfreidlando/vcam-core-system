<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Port\MoneyPoolLock;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL transaction-scoped advisory lock, keyed on the pool
 * (project + currency) — the same mechanism `identity` uses to
 * serialise API-key issuance per project
 * (`EloquentApiKeyRepository::lockProject()`).
 *
 * Released at commit or rollback, never held across requests: the
 * resolution that takes it reads the pool's balance, decides, and moves
 * the money, all inside one transaction (ADR-0077 §4).
 */
final class PostgresMoneyPoolLock implements MoneyPoolLock
{
    public function lockPool(string $projectId, string $currency): void
    {
        DB::select(
            "SELECT pg_advisory_xact_lock(hashtextextended('payment_pool:' || ? || ':' || ?, 0))",
            [$projectId, $currency],
        );
    }
}
