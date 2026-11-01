<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0070 §1 — the customer of a project.
 *
 * The project is the source of truth for its customer; the Core keeps the
 * minimal copy the billing and the portal need. `externalRef` is the
 * correlation key — opaque to the Core, format-checked so it can never be
 * injected into a URL, a log or a cache key.
 *
 * The email and the phone are held **in clear** here and encrypted at the
 * repository boundary (ADR-0083): the domain checks their format, the
 * infrastructure never lets a clear value reach the disk.
 */
final class Customer
{
    /**
     * @param array<string, mixed> $metadata technical identifiers of the
     *                                       project — never personal data
     *                                       (ADR-0070 §1)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $externalRef,
        public string $displayName,
        public ?string $email,
        public ?string $phone,
        public string $locale,
        public array $metadata,
        public CustomerStatus $status,
        public int $version,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $erasedAt = null,
    ) {}

    /**
     * A suspended customer keeps its invoices due but loses its rights
     * (ADR-0070 §2). An erased customer is final.
     */
    public function suspend(DateTimeImmutable $at): void
    {
        $this->status = CustomerStatus::Suspended;
        $this->updatedAt = $at;
    }

    public function reactivate(DateTimeImmutable $at): void
    {
        $this->status = CustomerStatus::Active;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0083 — the erasure destroys the identity, not the history: the
     * row stays (the invoices reference it), the personal values go.
     */
    public function erase(DateTimeImmutable $at): void
    {
        $this->email = null;
        $this->phone = null;
        $this->displayName = '';
        $this->metadata = [];
        $this->status = CustomerStatus::Erased;
        $this->erasedAt = $at;
        $this->updatedAt = $at;
    }

    public function isErased(): bool
    {
        return $this->status === CustomerStatus::Erased;
    }

    /**
     * ADR-0072 §3 — the rights follow the state. A suspended or erased
     * customer has none.
     */
    public function hasAccess(): bool
    {
        return $this->status === CustomerStatus::Active;
    }

    /**
     * @param array<string, mixed> $metadata technical identifiers of the
     *                                       project — never personal data
     *                                       (ADR-0070 §1)
     */
    public function updateProfile(
        string $displayName,
        ?string $email,
        ?string $phone,
        string $locale,
        array $metadata,
        DateTimeImmutable $at,
    ): void {
        $this->displayName = $displayName;
        $this->email = $email;
        $this->phone = $phone;
        $this->locale = $locale;
        $this->metadata = $metadata;
        $this->updatedAt = $at;
    }
}
