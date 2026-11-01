<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * Audit 2026-10-29 #6 / G-5 — the console always keeps one active platform
 * administrator, whether the last one is revoked (`LastPlatformAdmin`) or
 * demoted by a role change (this refusal). Without one, nobody can invite,
 * revoke or recover an account.
 *
 * The console answers **409**.
 */
final class LastPlatformAdminDemotion extends RuntimeException
{
    public const string CODE = 'last-platform-admin';

    public static function forOperator(string $operatorId): self
    {
        return new self(sprintf('Le compte « %s » est le dernier administrateur de plateforme actif : nommez-en un autre avant de modifier son rôle.', $operatorId));
    }
}
