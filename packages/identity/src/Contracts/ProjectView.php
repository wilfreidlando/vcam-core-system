<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class ProjectView
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public string $status,
    ) {}
}
