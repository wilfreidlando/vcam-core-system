<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListOrganizationMembers as ListOrganizationMembersPort;
use Core\Identity\Contracts\ListOrganizationMembersCommand;
use Core\Identity\Contracts\OrganizationMembersView;
use Core\Identity\Contracts\OrganizationMemberView;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §1 — the members of one organization, and the role each of
 * them holds there.
 *
 * The scope is required (ADR-0069 §2): an organization outside the
 * caller's scope answers 404 — never a listing of another tenant's
 * members, and never a 403 that would confirm its existence.
 */
final readonly class ListOrganizationMembers implements ListOrganizationMembersPort
{
    public function __construct(
        private OrganizationMembershipRepository $memberships,
        private OperatorRepository $operators,
        private OrganizationRepository $organizations,
    ) {}

    public function list(ListOrganizationMembersCommand $command, AccessScope $scope): OrganizationMembersView
    {
        // Visibility first, from the scope alone: an organization outside
        // it is not even looked up.
        if (! $scope->allowsOrganization($command->organizationId)) {
            throw OrganizationNotVisible::withId($command->organizationId);
        }

        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotVisible::withId($command->organizationId);

        $items = [];

        foreach ($this->memberships->findActiveByOrganization($organization->id) as $membership) {
            $operator = $this->operators->find($membership->operatorId);

            $items[] = new OrganizationMemberView(
                operatorId: $membership->operatorId,
                email: $operator->email ?? '',
                name: $operator->name ?? '',
                role: $membership->role->value,
                joinedAt: $membership->createdAt,
            );
        }

        return new OrganizationMembersView($items, count($items));
    }
}
