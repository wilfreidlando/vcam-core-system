<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Support\OrganizationMembershipPolicy;
use Core\Identity\Contracts\ChangeOrganizationMemberRole as ChangeOrganizationMemberRolePort;
use Core\Identity\Contracts\ChangeOrganizationMemberRoleCommand;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0069 — changes the role one account holds in one organization.
 *
 * Role changes are audited (ADR-0069 "Sécurité"); nobody grants a
 * platform role through this path, an `org_admin` neither grants
 * `org_owner` nor touches an owner, and the demotion of the **last**
 * active owner is refused exactly like its revocation (`409 last-owner`)
 * — under the same owner-row lock (see `RevokeOrganizationMember`).
 */
final readonly class ChangeOrganizationMemberRole implements ChangeOrganizationMemberRolePort
{
    public function __construct(
        private OrganizationMembershipRepository $memberships,
        private OrganizationRepository $organizations,
        private OrganizationMembershipPolicy $policy,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function changeRole(
        ChangeOrganizationMemberRoleCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): void {
        $this->policy->assertVisible($scope, $command->organizationId);

        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotVisible::withId($command->organizationId);

        $role = $this->policy->resolveAssignableRole($command->role, $organization->id);

        $this->unitOfWork->transactional(function () use ($organization, $role, $command, $actor, $correlationId): void {
            $activeOwners = $this->memberships->lockActiveOwners($organization->id);
            // Same discipline for the second pair of eyes on the money: the
            // last `org_finance` carries `money-exceptions:write` and cannot
            // be demoted (ADR-0069, symétrie du dernier propriétaire).
            $activeFinances = $this->memberships->lockActiveWithRole($organization->id, OrganizationRole::Finance);

            $actorRole = $this->policy->assertCanManageMembers($command->actorOperatorId, $organization->id);
            $this->policy->assertCanGrant($actorRole, $organization->id, $role);

            $membership = $this->memberships->findActive($organization->id, $command->operatorId)
                ?? throw OrganizationMemberNotFound::forMember($organization->id, $command->operatorId);

            $this->policy->assertCanActOn($actorRole, $membership);

            $previousRole = $membership->role;
            if ($previousRole === $role) {
                return;
            }

            $this->policy->assertNotLastOwner($membership, $activeOwners);
            $this->policy->assertNotLastFinance($membership, $activeFinances);

            $now = $this->clock->now();
            $membership->role = $role;
            $this->memberships->save($membership);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'organization.member_role_changed',
                resource: 'organization:'.$organization->id,
                before: ['operator_id' => $membership->operatorId, 'role' => $previousRole->value],
                after: ['operator_id' => $membership->operatorId, 'role' => $role->value],
                context: ['membership_id' => $membership->id],
                correlationId: $correlationId,
                occurredAt: $now,
                organizationId: $organization->id,
            ));
        });
    }
}
