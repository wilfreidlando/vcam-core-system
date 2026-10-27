<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0070 §1 — the customer's identity as the project declares it.
 *
 * `externalRef` is the correlation key with the project: opaque to the
 * Core, only format-checked so it can never be injected into a URL, a log
 * or a cache key. `email` and `phone` are optional individually; the
 * Core stores them encrypted (ADR-0083) and returns them only to a
 * caller holding `customers:read` (ADR-0070 §Sécurité).
 */
final readonly class UpsertCustomerCommand
{
    /**
     * @param array<string, mixed> $metadata technical identifiers of the project, never personal data (ADR-0070 §1)
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $externalRef,
        public string $displayName,
        public ?string $email,
        public ?string $phone,
        public string $locale,
        public array $metadata,
        public ?int $expectedVersion,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
