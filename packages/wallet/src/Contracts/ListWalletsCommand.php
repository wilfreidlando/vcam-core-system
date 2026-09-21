<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class ListWalletsCommand
{
    public function __construct(
        public string $projectId,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $search = null,
    ) {}
}
