<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 — the account may see the organization but may not manage its
 * members: it does not hold `members:write` there (`org_owner` holds it
 * through `*`, `org_admin` explicitly; `org_finance`, `org_support` and
 * `org_viewer` do not; a platform role holds it only if its own
 * permissions do). This is also the answer when the account is not a
 * member at all but still sees the organization.
 *
 * The console answers **403** — the organization is visible, so a 403
 * confirms nothing the caller does not already know. An organization
 * outside the scope answers 404 (`OrganizationNotVisible`) instead.
 */
final class OrganizationMemberManagementForbidden extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'organization-members-forbidden';

    public function __construct(public readonly string $organizationId)
    {
        parent::__construct(sprintf(
            'Managing the members of organization %s requires the members:write permission there.',
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
        return 403;
    }
}
