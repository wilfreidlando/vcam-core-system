<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0069 §4 — the invitation of one email address to join one
 * organization with one role.
 *
 * `role` is a raw canonical role string (`org_admin`, …), not the
 * `OrganizationRole` enum: the enum lives in the Domain layer, which
 * `Contracts/` may not reference (G3c). The use case converts it and
 * refuses anything that is not one of the five roles ADR-0069 §1 fixes.
 */
final readonly class InviteOrganizationMemberCommand
{
    public function __construct(
        public string $organizationId,
        public string $email,
        public string $role,
        /**
         * The console account asking for the invitation — the only one
         * allowed to manage the members of the organization (an
         * `org_owner`, or a platform operator).
         */
        public string $actorOperatorId,
    ) {}
}
