<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Idempotency\IdempotencyScope;

final readonly class ProjectIdempotencyScope implements IdempotencyScope
{
    public function __construct(
        private ProjectContext $project,
    ) {}

    public function current(): string
    {
        return $this->project->projectId;
    }
}
