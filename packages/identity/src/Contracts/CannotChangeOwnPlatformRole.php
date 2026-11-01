<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * Audit 2026-10-29 #6 / G-5 — a console account never changes its own
 * platform role: whoever demotes themselves loses their own privileges and,
 * if they were the last administrator, the whole console. Another
 * administrator does it.
 *
 * The console answers **409**.
 */
final class CannotChangeOwnPlatformRole extends RuntimeException
{
    public const string CODE = 'cannot-change-own-platform-role';

    public static function forOperator(string $operatorId): self
    {
        return new self(sprintf('Le compte « %s » ne peut pas modifier son propre rôle : demandez à un autre administrateur.', $operatorId));
    }
}
