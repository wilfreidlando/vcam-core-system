<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Project;

interface ProjectRepository
{
    public function save(Project $project): void;

    public function find(string $id): ?Project;

    /**
     * Newest first, optionally narrowed to one `organizationId` (ADR-0045
     * — the operator console's project listing). `$search`, when given,
     * matches a case-insensitive substring of `name`.
     *
     * @param list<string>|null $organizationIds when given, only the
     *                                           projects of those
     *                                           organizations are listed
     *                                           (ADR-0069 §2 — the
     *                                           console's scope)
     * @return array{items: list<Project>, total: int}
     */
    public function findAll(?string $organizationId, int $page, int $perPage, ?string $search = null, ?array $organizationIds = null): array;

    /**
     * Every project of an organization — the console's scope computation
     * (ADR-0069 §2) needs the projects an account may see.
     *
     * @return list<Project>
     */
    public function findAllByOrganization(string $organizationId): array;
}
