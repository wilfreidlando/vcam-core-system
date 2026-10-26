<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 — changes the role one account holds in one organization.
 *
 * Demoting the **last** active owner is refused exactly like
 * revoking it (`last-owner`): an organization whose last owner lost the
 * role could no longer be administered.
 */
interface ChangeOrganizationMemberRole
{
    /**
     * @throws OrganizationRefusal when the membership does not exist,
     *                             when the caller may not manage the
     *                             members of the organization, when the
     *                             requested role is refused (unknown
     *                             role, platform role, elevation), or
     *                             when the change would demote the
     *                             **last** active owner.
     */
    public function changeRole(
        ChangeOrganizationMemberRoleCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): void;
}
