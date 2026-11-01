<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Support\OrganizationMembershipPolicy;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\RevokeOrganizationMember as RevokeOrganizationMemberPort;
use Core\Identity\Contracts\RevokeOrganizationMemberCommand;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0069 §1 — revokes the membership of one account in one
 * organization. The row is kept for the audit trail; the active
 * membership is what disappears.
 *
 * Concurrency: everything after the visibility check runs in one
 * transaction that first **locks the active owner rows** of the
 * organization. Two owners revoking each other at the same time are
 * serialized; the second one re-reads its own membership (now revoked:
 * refused) or counts a single owner left (`409 last-owner`) — never zero
 * owners.
 */
final readonly class RevokeOrganizationMember implements RevokeOrganizationMemberPort
{
    public function __construct(
        private OrganizationMembershipRepository $memberships,
        private OrganizationRepository $organizations,
        private OrganizationMembershipPolicy $policy,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function revoke(
        RevokeOrganizationMemberCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): void {
        $this->policy->assertVisible($scope, $command->organizationId);

        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotVisible::withId($command->organizationId);

        $this->unitOfWork->transactional(function () use ($organization, $command, $actor, $correlationId): void {
            $activeOwners = $this->memberships->lockActiveOwners($organization->id);
            // Same discipline for the second pair of eyes on the money: the
            // last `org_finance` carries `money-exceptions:write` and cannot
            // be revoked (ADR-0069, symétrie du dernier propriétaire).
            $activeFinances = $this->memberships->lockActiveWithRole($organization->id, OrganizationRole::Finance);

            // Permission before existence: an account that may not manage
            // the members learns nothing about who they are.
            $actorRole = $this->policy->assertCanManageMembers($command->actorOperatorId, $organization->id);

            $membership = $this->memberships->findActive($organization->id, $command->operatorId)
                ?? throw OrganizationMemberNotFound::forMember($organization->id, $command->operatorId);

            $this->policy->assertCanActOn($actorRole, $membership);
            $this->policy->assertNotLastOwner($membership, $activeOwners);
            $this->policy->assertNotLastFinance($membership, $activeFinances);

            $now = $this->clock->now();
            $membership->revoke($now);
            $this->memberships->save($membership);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'organization.member_revoked',
                resource: 'organization:'.$organization->id,
                before: ['operator_id' => $membership->operatorId, 'role' => $membership->role->value],
                after: null,
                context: ['membership_id' => $membership->id],
                correlationId: $correlationId,
                occurredAt: $now,
                organizationId: $organization->id,
            ));
        });
    }
}
