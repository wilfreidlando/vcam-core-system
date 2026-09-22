<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface UpdateProject
{
    /**
     * @throws RuntimeException if `$command->projectId` doesn't resolve
     *                          to an existing Project.
     */
    public function update(UpdateProjectCommand $command, string $actor, ?string $correlationId): void;
}
