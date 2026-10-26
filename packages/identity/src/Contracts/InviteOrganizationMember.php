<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §4 — invites a member into an organization.
 *
 * The scope is required (ADR-0069 §2): an organization outside the
 * caller's scope does not exist as far as the caller is concerned.
 */
interface InviteOrganizationMember
{
    /**
     * @throws OrganizationRefusal when the caller may not manage the
     *                             members of the organization, when the
     *                             requested role is refused, or when an
     *                             invitation for this email is already
     *                             pending.
     */
    public function invite(
        InviteOrganizationMemberCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): CreatedOrganizationInvitation;
}
