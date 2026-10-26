<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class RevokeOrganizationMemberCommand
{
    public function __construct(
        public string $organizationId,
        public string $operatorId,
        /**
         * The console account asking for the revocation — the only one
         * allowed to manage the members of the organization.
         */
        public string $actorOperatorId,
    ) {}
}
