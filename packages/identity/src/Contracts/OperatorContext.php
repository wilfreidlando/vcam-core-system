<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;

/**
 * "Which operator is calling, with which role" — resolved once per request
 * by `ResolveOperatorMiddleware` and bound as a scoped singleton. Mirrors
 * `ProjectContext` for the machine-identity plane; `admin`'s controllers
 * depend on THIS, never on `Core\Identity\Domain\Model\Operator` directly
 * (G3c).
 */
final readonly class OperatorContext
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public ?OperatorRole $role,
        // ADR-0069 §2 — what this account may see. A platform role sees
        // every organization; otherwise it is the organizations the account
        // belongs to.
        public AccessScope $scope = new AccessScope(false, [], [], false, []),
    ) {}

    /**
     * A **platform** permission: the actions reserved to the platform
     * (console accounts, global channel sessions, Horizon, global
     * settings). An account with only organization memberships has none of
     * them — its rights are the organization's, checked with
     * `hasOrganizationPermission()`.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->role?->hasPermission($permission) ?? false;
    }

    /**
     * True when the account holds the permission in **at least one** of
     * its organizations — the console's coarse check, before the resource
     * is resolved. The fine-grained check happens on the resolved
     * resource's organization.
     */
    public function hasPermissionAnywhere(string $permission): bool
    {
        return ! $this->scopeFor($permission)->isEmpty();
    }

    /**
     * ADR-0069 §2 — what this account may do `$permission` on: the scope
     * narrowed to the organizations where it holds that permission.
     *
     * The one scope a console page should read through: the page opens when
     * it is not empty, and the data it shows is exactly what the permission
     * opens — never an organization the account merely sees through another
     * role. A platform role holds its role's permissions everywhere, or
     * nowhere.
     */
    public function scopeFor(string $permission): AccessScope
    {
        if ($this->role !== null) {
            return $this->role->hasPermission($permission) ? $this->scope : AccessScope::none();
        }

        return $this->scope->restrictedTo($permission);
    }

    /**
     * A permission **inside** one organization: the account's role there
     * decides, not its platform role.
     */
    public function hasOrganizationPermission(string $organizationId, string $permission): bool
    {
        // A platform role holds its permissions everywhere.
        if ($this->role !== null) {
            return $this->role->hasPermission($permission);
        }

        return $this->scope->hasPermission($organizationId, $permission);
    }

    public function seesEverything(): bool
    {
        return $this->scope->allOrganizations;
    }

    /**
     * The organization whose permissions decide an action on `$projectId`.
     * A platform role acts on any organization; an organization-only
     * account acts only where its role grants the permission.
     */
    public function canActOnProject(string $organizationId, string $permission): bool
    {
        return $this->hasOrganizationPermission($organizationId, $permission);
    }

    /**
     * True when the account holds a **platform role** — ADR-0069 §2: "les
     * actions réservées à la plateforme restent sous rôle de plateforme"
     * (console accounts of the platform, global channel sessions, Horizon,
     * batch operations that span every project such as a reconciliation
     * run). Those actions are not organization actions: no organization
     * role can ever be granted them, whatever its permission list says.
     */
    public function isPlatformRole(): bool
    {
        return $this->role !== null;
    }
}
