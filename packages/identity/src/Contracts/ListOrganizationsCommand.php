<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class ListOrganizationsCommand
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
