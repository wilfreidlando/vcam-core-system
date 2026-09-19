<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class OrganizationView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
    ) {}
}
