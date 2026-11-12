<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * The read model of a customer — never
 * `Core\Billing\Domain\Model\Customer`, which stays internal (G3c).
 *
 * `$status` is the canonical `CustomerStatus` enum's `->value` ('active',
 * 'suspended', 'erased'), never the enum itself (G3c).
 *
 * `email` and `phone` are the values as they are stored: they leave the Core only
 * to a caller holding `customers:read` (ADR-0070 §Sécurité).
 */
final readonly class CustomerView
{
    /**
     * @param array<string, mixed> $metadata technical identifiers of the project, never personal data
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $externalRef,
        public string $displayName,
        public ?string $email,
        public ?string $phone,
        public string $locale,
        public array $metadata,
        public string $status,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
        public ?string $erasedAt = null,
    ) {}
}
