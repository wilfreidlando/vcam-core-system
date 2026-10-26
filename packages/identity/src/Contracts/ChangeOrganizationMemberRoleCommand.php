<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class ChangeOrganizationMemberRoleCommand
{
    public function __construct(
        public string $organizationId,
        public string $operatorId,
        /**
         * The raw canonical role string the member must hold from now
         * on (`org_admin`, …).
         */
        public string $role,
        /**
         * The console account asking for the change — the only one
         * allowed to manage the members of the organization.
         */
        public string $actorOperatorId,
    ) {}
}
