<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use DateTimeImmutable;

/**
 * A Project's own credentials at a payment provider — e.g. one MyCoolPay
 * "application" (public + private key pair). One per (project, provider)
 * (ADR-0066). The public key also routes inbound callbacks back to their
 * project, so a given public key belongs to exactly one project across the
 * whole Core.
 *
 * `privateKey` is plaintext in memory only — encrypted at rest by the
 * Infrastructure adapter, never shown back once registered.
 */
final readonly class ProviderApplication
{
    public function __construct(
        public string $projectId,
        public string $providerCode,
        public string $publicKey,
        public string $privateKey,
        public ?string $id = null,
        public ?string $label = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    public function maskedPublicKey(): string
    {
        $length = strlen($this->publicKey);

        return $length <= 8 ? str_repeat('•', $length) : substr($this->publicKey, 0, 4).'…'.substr($this->publicKey, -4);
    }
}
