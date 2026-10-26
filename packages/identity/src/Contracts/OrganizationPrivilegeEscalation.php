<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 — "Élévation de privilège : un `org_admin` ne peut pas
 * s'attribuer `org_owner`".
 *
 * Raised when an account that manages the members of an organization
 * without owning it (an `org_admin`) either grants `org_owner` — to
 * anyone, itself included — or acts on the membership of an `org_owner`
 * (revoking or demoting it): both would let it reach above its own role.
 * The console answers **403**.
 */
final class OrganizationPrivilegeEscalation extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'privilege-escalation';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $role,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forRole(string $organizationId, string $role): self
    {
        return new self($organizationId, $role, sprintf(
            'The role "%s" cannot be granted in organization %s: it would elevate the grantee above the role of the account granting it.',
            $role,
            $organizationId,
        ));
    }

    /**
     * An account that is not an owner touching an owner's membership.
     */
    public static function onOwnerMembership(string $organizationId): self
    {
        return new self($organizationId, 'org_owner', sprintf(
            'Only an owner of organization %s can revoke or change the role of another owner.',
            $organizationId,
        ));
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
