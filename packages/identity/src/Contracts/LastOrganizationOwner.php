<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 — "Dernier propriétaire : la révocation du dernier
 * `org_owner` actif est refusée (`409 last-owner`)".
 *
 * An organization without an owner could never be administered again:
 * the owner is the only role allowed to manage the members. Revoking —
 * or demoting — the last active owner is therefore refused, and the
 * console answers **409** with the code `last-owner`.
 */
final class LastOrganizationOwner extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'last-owner';

    public function __construct(public readonly string $organizationId)
    {
        parent::__construct(sprintf(
            'Organization %s has no other active owner: its last org_owner cannot be revoked or demoted.',
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
