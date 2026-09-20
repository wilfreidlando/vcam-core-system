<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface CreateProject
{
    /**
     * @throws RuntimeException if `$command->organizationId` doesn't
     *                          resolve to an existing Organization. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function create(CreateProjectCommand $command, string $actor, ?string $correlationId): ProjectView;
}
