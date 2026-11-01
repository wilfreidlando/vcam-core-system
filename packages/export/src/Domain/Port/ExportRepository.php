<?php

declare(strict_types=1);

namespace Core\Export\Domain\Port;

use Core\Export\Domain\Model\ProjectExport;
use DateTimeImmutable;

interface ExportRepository
{
    public function create(ProjectExport $export): void;

    public function save(ProjectExport $export): void;

    public function find(string $id): ?ProjectExport;

    /**
     * A project's exports, newest first.
     *
     * @param list<string>|null $projectIds when given, only the exports of those projects (ADR-0069 §2)
     * @return list<ProjectExport>
     */
    public function findByProject(string $projectId, int $limit, ?array $projectIds = null): array;

    /**
     * The exports that still hold a file although their retention is over,
     * oldest first — one bounded batch of the daily purge.
     *
     * @return list<ProjectExport>
     */
    public function findWithExpiredFile(DateTimeImmutable $now, int $limit): array;
}
