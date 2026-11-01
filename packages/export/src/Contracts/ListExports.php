<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

use Core\Support\Access\AccessScope;

interface ListExports
{
    /**
     * A project's exports, newest first. The scope is **required**
     * (ADR-0069 §2, G16): a project outside it yields nothing.
     *
     * @return list<ExportView>
     */
    public function list(string $projectId, int $limit, AccessScope $scope): array;
}
