<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069, symétrie du « dernier propriétaire » — « dernier `org_finance` ».
 *
 * Le rôle `org_finance` porte `money-exceptions:write` : c'est lui qui
 * résout les exceptions d'argent, et la règle des **quatre yeux** y
 * exige un second compte (ADR-0081 S-15). Un `org_admin` — qui porte
 * `members:write` — pouvait rétrograder le dernier `org_finance` : la
 * seconde paire d'yeux sur l'argent disparaissait, et plus personne dans
 * l'organisation ne pouvait résoudre une exception.
 *
 * La révocation et la rétrogradation du dernier `org_finance` actif sont
 * donc refusées, comme celles du dernier `org_owner` (`409 last-owner`).
 * La console répond **409** avec le code `last-finance`.
 */
final class LastOrganizationFinance extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'last-finance';

    public function __construct(public readonly string $organizationId)
    {
        parent::__construct(sprintf(
            'Organization %s has no other active org_finance: its last org_finance cannot be revoked or demoted.',
            $organizationId,
        ));
    }

    public static function forOrganization(string $organizationId): self
    {
        return new self($organizationId);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
