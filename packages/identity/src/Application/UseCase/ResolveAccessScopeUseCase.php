<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ResolveAccessScope;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — the scope of a console account.
 *
 * A platform role sees every organization and every project. An account
 * with only memberships sees the organizations it belongs to, the projects
 * of those organizations, and the permissions its role carries **in each
 * organization** — not the permissions of its platform role.
 */
final readonly class ResolveAccessScopeUseCase implements ResolveAccessScope
{
    public function __construct(
        private OperatorRepository $operators,
        private OrganizationMembershipRepository $memberships,
        private ProjectRepository $projects,
    ) {}

    public function forOperator(string $operatorId): AccessScope
    {
        $operator = $this->operators->find($operatorId);

        // An unknown account sees nothing — never everything.
        if ($operator === null) {
            return AccessScope::none();
        }

        // ADR-0069 §1: a **platform role** — any of them — means the
        // account sees every organization; its permissions still restrict
        // what it may do. Only an account *without* a platform role is
        // restricted to the organizations it belongs to.
        if ($operator->role !== null) {
            return AccessScope::platform();
        }

        $organizationIds = [];
        $permissionsByOrganization = [];

        foreach ($this->memberships->findActiveByOperator($operatorId) as $membership) {
            $organizationIds[] = $membership->organizationId;
            $permissionsByOrganization[$membership->organizationId] = $membership->role->permissions();
        }

        $projectIds = [];
        $projectIdsByOrganization = [];
        foreach (array_unique($organizationIds) as $organizationId) {
            $projectIdsByOrganization[$organizationId] = [];
            foreach ($this->projects->findAllByOrganization($organizationId) as $project) {
                $projectIds[] = $project->id;
                $projectIdsByOrganization[$organizationId][] = $project->id;
            }
        }

        return new AccessScope(
            allOrganizations: false,
            organizationIds: array_values(array_unique($organizationIds)),
            permissionsByOrganization: $permissionsByOrganization,
            allProjects: false,
            projectIds: array_values(array_unique($projectIds)),
            projectIdsByOrganization: $projectIdsByOrganization,
        );
    }
}
