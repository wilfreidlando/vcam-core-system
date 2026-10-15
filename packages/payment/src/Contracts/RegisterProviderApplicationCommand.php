<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Creates the (project, provider) application, or updates its
 * keys when it already exists (ADR-0060). On update, a null `privateKey`
 * keeps the stored one — operators replace only what changed.
 */
final readonly class RegisterProviderApplicationCommand
{
    public function __construct(
        public string $projectId,
        public string $providerCode,
        public string $publicKey,
        public ?string $privateKey,
        public ?string $label = null,
    ) {}
}
