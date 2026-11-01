<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §1 — the account is already an active member of the
 * organization: one account holds one role per organization (the partial
 * unique index on `organization_memberships`). Inviting it again, or
 * accepting a second invitation for it, is refused; its role is changed
 * through the role-change operation instead.
 *
 * The console answers **409**.
 */
final class AlreadyOrganizationMember extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'already-member';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $email,
    ) {
        parent::__construct(sprintf(
            '"%s" is already a member of organization %s: change its role instead.',
            $email,
            $organizationId,
        ));
    }

    public static function forEmail(string $organizationId, string $email): self
    {
        return new self($organizationId, $email);
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
