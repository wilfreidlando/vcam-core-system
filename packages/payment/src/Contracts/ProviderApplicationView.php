<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Never carries the private key — it is write-only once registered.
 */
final readonly class ProviderApplicationView
{
    public function __construct(
        public string $providerCode,
        public string $publicKey,
        public string $id = '',
        public ?string $label = null,
        public string $maskedPublicKey = '',
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
