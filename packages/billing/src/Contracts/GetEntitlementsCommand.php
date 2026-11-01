<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

final readonly class GetEntitlementsCommand
{
    public function __construct(
        public string $projectId,
        public string $customerRef,
    ) {}
}
