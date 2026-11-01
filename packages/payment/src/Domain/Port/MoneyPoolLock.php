<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

/**
 * Serialises the movements that spend the SAME pool of money.
 *
 * Two different exceptions of a project can be settled at the same
 * time, and each one reads the pool's balance before spending it.
 * Without a lock, both read the same balance and both spend it: the
 * pool ends up overdrawn and the platform has created value
 * (ADR-0078 §2, invariant 4).
 *
 * The lock is taken BEFORE the balance is read and is held until the
 * end of the current transaction, so the second settlement reads the
 * balance the first one left.
 */
interface MoneyPoolLock
{
    /**
     * Locks the pool of one project and one currency until the end of
     * the current transaction.
     */
    public function lockPool(string $projectId, string $currency): void;
}
