<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class RegisterProviderApplicationCommand
{
    public function __construct(
        public string $projectId,
        public string $providerCode,
        public string $publicKey,
        public string $privateKey,
    ) {}
}
