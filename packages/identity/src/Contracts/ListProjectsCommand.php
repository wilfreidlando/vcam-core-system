<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class ListProjectsCommand
{
    public function __construct(
        public ?string $organizationId = null,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $search = null,
    ) {}
}
