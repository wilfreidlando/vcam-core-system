<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §1 — the members of one organization, and the role each of
 * them holds there.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a read port the
 * console calls must carry the scope of the caller, so that an
 * organization outside that scope answers 404 instead of listing
 * another tenant's members.
 */
interface ListOrganizationMembers
{
    /**
     * @throws OrganizationRefusal when the organization does not exist
     *                             or is outside the caller's scope.
     */
    public function list(ListOrganizationMembersCommand $command, AccessScope $scope): OrganizationMembersView;
}
