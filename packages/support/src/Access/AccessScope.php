<?php

declare(strict_types=1);

namespace Core\Support\Access;

/**
 * ADR-0069 §2 — "which organizations may this console account see, and
 * with which permissions" — resolved once per request by the operator
 * middleware and passed to **every** listing/read port the console calls.
 *
 * It lives in the shared kernel, not in `identity`: the `ledger` module
 * depends on no other module (G2), and its read ports are called by the
 * console too. The value object holds data only — who is allowed what is
 * `identity`'s business (`OrganizationRole`).
 *
 * Two levels, because a project always belongs to an organization:
 * - organizations: the tenants the account may see;
 * - projects: the projects of those organizations, so a project-scoped
 *   read can filter without asking `identity` who owns a project.
 *
 * A platform role sees everything: `allOrganizations` and `allProjects`
 * are then true and no id is listed.
 *
 * Visibility is not permission. An account may see an organization through
 * a role that does not carry the permission an action needs (an
 * `org_admin` holds no `payments:read`). `restrictedTo()` narrows the scope
 * to the organizations where a permission **is** held — so a page gated by
 * a permission reads exactly the data that permission opens, and nothing
 * the account merely sees.
 */
final readonly class AccessScope
{
    /**
     * @param list<string> $organizationIds
     * @param array<string, list<string>> $permissionsByOrganization organization id => permissions
     * @param list<string> $projectIds
     * @param array<string, list<string>> $projectIdsByOrganization organization id => its projects
     */
    public function __construct(
        public bool $allOrganizations,
        public array $organizationIds,
        public array $permissionsByOrganization,
        public bool $allProjects = false,
        public array $projectIds = [],
        public array $projectIdsByOrganization = [],
    ) {}

    /**
     * A platform role: every organization, every project.
     */
    public static function platform(): self
    {
        return new self(true, [], [], true, []);
    }

    /**
     * A scope allowing exactly one project — what a caller that already
     * holds a project id needs ("does this project exist?"), whether it is
     * the project's own API key or a service validating a reference.
     */
    public static function forProject(string $projectId): self
    {
        return new self(false, [], [], false, [$projectId]);
    }

    /**
     * A scope allowing exactly one organization and, optionally, its
     * projects.
     *
     * @param list<string> $projectIds
     */
    public static function forOrganization(string $organizationId, array $projectIds = []): self
    {
        return new self(false, [$organizationId], [], false, $projectIds, [$organizationId => $projectIds]);
    }

    /**
     * An account without any membership sees nothing — not even an empty
     * list of its own.
     */
    public static function none(): self
    {
        return new self(false, [], [], false, []);
    }

    public function allowsOrganization(string $organizationId): bool
    {
        return $this->allOrganizations || in_array($organizationId, $this->organizationIds, true);
    }

    public function allowsProject(string $projectId): bool
    {
        return $this->allProjects || in_array($projectId, $this->projectIds, true);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $organizationId): array
    {
        if ($this->allOrganizations) {
            return ['*'];
        }

        return $this->permissionsByOrganization[$organizationId] ?? [];
    }

    public function hasPermission(string $organizationId, string $permission): bool
    {
        $permissions = $this->permissionsFor($organizationId);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * The same scope, narrowed to the organizations where `$permission` is
     * held — and to the projects of those organizations only.
     *
     * A platform scope is returned unchanged: a platform role's permissions
     * are its role's, decided by `identity`, not by the scope. A scope that
     * carries no permission at all (a project's own API key, for instance)
     * narrows to nothing.
     */
    public function restrictedTo(string $permission): self
    {
        if ($this->allOrganizations) {
            return $this;
        }

        $organizationIds = [];
        $permissionsByOrganization = [];
        $projectIdsByOrganization = [];
        $projectIds = [];

        foreach ($this->organizationIds as $organizationId) {
            if (! $this->hasPermission($organizationId, $permission)) {
                continue;
            }

            $organizationIds[] = $organizationId;
            $permissionsByOrganization[$organizationId] = $this->permissionsFor($organizationId);
            $projectIdsByOrganization[$organizationId] = $this->projectIdsByOrganization[$organizationId] ?? [];

            foreach ($projectIdsByOrganization[$organizationId] as $projectId) {
                $projectIds[] = $projectId;
            }
        }

        return new self(
            false,
            $organizationIds,
            $permissionsByOrganization,
            false,
            array_values(array_unique($projectIds)),
            $projectIdsByOrganization,
        );
    }

    /**
     * True when the scope sees nothing at all.
     */
    public function isEmpty(): bool
    {
        return ! $this->allOrganizations
            && ! $this->allProjects
            && $this->organizationIds === []
            && $this->projectIds === [];
    }

    /**
     * The ids a listing must be restricted to. An empty list means
     * "no restriction" only for a platform scope; for an ordinary one it
     * means "nothing is visible".
     *
     * @return list<string>|null null when there is no restriction to apply
     */
    public function projectFilter(): ?array
    {
        return $this->allProjects ? null : $this->projectIds;
    }

    /**
     * @return list<string>|null
     */
    public function organizationFilter(): ?array
    {
        return $this->allOrganizations ? null : $this->organizationIds;
    }
}
