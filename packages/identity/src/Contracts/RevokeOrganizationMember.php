<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §1 — revokes the membership of one account in one
 * organization.
 *
 * The row is kept (the audit trail needs it); only the active
 * membership disappears, which is what the partial unique index on
 * `organization_memberships` enforces.
 */
interface RevokeOrganizationMember
{
    /**
     * @throws OrganizationRefusal when the membership does not exist,
     *                             when the caller may not manage the
     *                             members of the organization, or when
     *                             the membership is the **last** active
     *                             owner (`last-owner`).
     */
    public function revoke(
        RevokeOrganizationMemberCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): void;
}
