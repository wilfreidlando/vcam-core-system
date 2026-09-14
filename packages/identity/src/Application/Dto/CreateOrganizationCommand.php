<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class CreateOrganizationCommand
{
    public function __construct(
        public string $name,
    ) {}
}
