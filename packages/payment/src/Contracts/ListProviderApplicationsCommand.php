<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class ListProviderApplicationsCommand
{
    public function __construct(
        public string $projectId,
    ) {}
}
