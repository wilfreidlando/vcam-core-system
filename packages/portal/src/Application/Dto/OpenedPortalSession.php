<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

final readonly class OpenedPortalSession
{
    public function __construct(
        public string $url,
        public string $expiresAt,
        public bool $customerCreated,
        public bool $subscriptionCreated,
    ) {}
}
