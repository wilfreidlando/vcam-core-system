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
     * @return array{items: list<Project>, total: int}
     */
    public function findAll(?string $organizationId, int $page, int $perPage, ?string $search = null): array;
}
