<?php

declare(strict_types=1);

namespace Core\Identity\Application\Support;

use Core\Identity\Contracts\InvalidOrganizationRole;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\OrganizationMemberManagementForbidden;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationPrivilegeEscalation;
use Core\Identity\Contracts\PlatformRoleAssignmentForbidden;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;

/**
 * ADR-0069 — the rules that decide who may manage the members of an
 * organization, and with which role.
 *
 * They live in one place because they are one rule, applied by three
 * operations (invite, revoke, change role): an account that may not
 * manage the members of an organization must not be able to slip
 * through one of the three doors.
 *
 * The rule is the permission model of the console (`OperatorContext::
 * scopeFor('members:write')`), re-derived here from the stored state
 * rather than trusted from the caller:
 *  - a **platform role** decides by its own permissions, whatever its
 *    memberships (`platform_admin` holds `*`; the other platform roles
 *    hold no `members:write`) — and when it holds the permission it acts
 *    as an owner of every organization;
 *  - otherwise the account's **membership role in this organization**
 *    decides: `org_owner` (`*`) and `org_admin` hold `members:write`, the
 *    other roles do not.
 *
 * It is re-read from storage because the use cases call it **inside**
 * their transaction, after the owner rows are locked: an owner revoked a
 * millisecond ago by a concurrent request no longer manages anything.
 */
final readonly class OrganizationMembershipPolicy
{
    public const string MANAGE_PERMISSION = 'members:write';

    public function __construct(
        private OperatorRepository $operators,
        private OrganizationMembershipRepository $memberships,
    ) {}

    /**
     * ADR-0069 §2 / S-04 — an organization outside the caller's scope does
     * not exist as far as the caller is concerned: 404, never 403.
     *
     * @throws OrganizationNotVisible
     */
    public function assertVisible(AccessScope $scope, string $organizationId): void
    {
        if (! $scope->allowsOrganization($organizationId)) {
            throw OrganizationNotVisible::withId($organizationId);
        }
    }

    /**
     * The role the account **acts with** when it manages the members of
     * this organization — `Owner` for a platform role holding
     * `members:write` — or a refusal when it holds no `members:write`
     * there.
     *
     * @throws OrganizationMemberManagementForbidden
     */
    public function assertCanManageMembers(string $actorOperatorId, string $organizationId): OrganizationRole
    {
        $actor = $this->operators->find($actorOperatorId);
        if ($actor === null || $actor->isRevoked()) {
            throw OrganizationMemberManagementForbidden::forOrganization($organizationId);
        }

        if ($actor->role !== null) {
            // A platform role decides by its own permissions — the same
            // rule as `OperatorContext::scopeFor()`.
            if ($actor->role->hasPermission(self::MANAGE_PERMISSION)) {
                return OrganizationRole::Owner;
            }

            throw OrganizationMemberManagementForbidden::forOrganization($organizationId);
        }

        $role = $this->memberships->findActive($organizationId, $actorOperatorId)?->role;
        if ($role === null || ! $role->hasPermission(self::MANAGE_PERMISSION)) {
            throw OrganizationMemberManagementForbidden::forOrganization($organizationId);
        }

        return $role;
    }

    /**
     * ADR-0069 "Sécurité" — "un `org_admin` ne peut pas s'attribuer
     * `org_owner`": only an owner (or a platform role acting as one)
     * grants the owner role, to anyone.
     *
     * @throws OrganizationPrivilegeEscalation
     */
    public function assertCanGrant(OrganizationRole $actorRole, string $organizationId, OrganizationRole $target): void
    {
        if ($target === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            throw OrganizationPrivilegeEscalation::forRole($organizationId, $target->value);
        }
    }

    /**
     * The other half of the same rule: an account that is not an owner
     * does not revoke nor demote an owner — removing the owners above it
     * would be an elevation by subtraction.
     *
     * @throws OrganizationPrivilegeEscalation
     */
    public function assertCanActOn(OrganizationRole $actorRole, OrganizationMembership $target): void
    {
        if ($target->role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            throw OrganizationPrivilegeEscalation::onOwnerMembership($target->organizationId);
        }
    }

    /**
     * ADR-0069 — the requested role, refused when it is a **platform**
     * role (a platform role is never granted through a membership) and
     * when it is not one of the five organization roles.
     *
     * @throws InvalidOrganizationRole when the role is unknown.
     * @throws PlatformRoleAssignmentForbidden when the role is a platform role.
     */
    public function resolveAssignableRole(string $rawRole, string $organizationId): OrganizationRole
    {
        $role = trim($rawRole);

        $platformRoles = array_column(OperatorRole::cases(), 'value');
        if (in_array($role, $platformRoles, true)) {
            throw PlatformRoleAssignmentForbidden::forRole($organizationId, $role);
        }

        $organizationRole = OrganizationRole::tryFrom($role);
        if ($organizationRole === null) {
            throw InvalidOrganizationRole::withValue($role);
        }

        return $organizationRole;
    }

    /**
     * ADR-0069 — "la révocation du dernier `org_owner` actif est refusée
     * (`409 last-owner`)". The same guard covers a demotion: an
     * organization whose last owner loses the role could no longer be
     * administered by its own members.
     *
     * `$activeOwners` is the count `lockActiveOwners()` returned at the
     * start of the transaction — with the owner rows still locked.
     *
     * @throws LastOrganizationOwner
     */
    public function assertNotLastOwner(OrganizationMembership $target, int $activeOwners): void
    {
        if ($target->role === OrganizationRole::Owner && $activeOwners <= 1) {
            throw LastOrganizationOwner::forOrganization($target->organizationId);
        }
    }
}
