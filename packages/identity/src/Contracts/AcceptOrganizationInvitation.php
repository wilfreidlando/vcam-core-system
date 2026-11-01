<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0069 §4 — consumes an activation token and creates the console
 * account it was issued for.
 *
 * The token is usable **once**: accepting marks the invitation as
 * accepted, and a second presentation of the same token is refused.
 */
interface AcceptOrganizationInvitation
{
    /**
     * @throws OrganizationRefusal when the token matches no invitation,
     *                             when the invitation has expired, has
     *                             already been accepted or has been
     *                             revoked, or when a console account
     *                             already exists for the invited email.
     */
    public function accept(AcceptOrganizationInvitationCommand $command): AcceptedOrganizationInvitation;
}
