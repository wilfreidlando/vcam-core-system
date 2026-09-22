<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class ListApiKeysCommand
{
    public function __construct(
        public string $projectId,
    ) {}
}
