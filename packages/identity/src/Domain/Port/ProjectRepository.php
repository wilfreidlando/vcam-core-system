<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Project;

interface ProjectRepository
{
    public function save(Project $project): void;

    public function find(string $id): ?Project;
}
