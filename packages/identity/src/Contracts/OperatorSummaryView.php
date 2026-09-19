<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

/**
 * `role` is the canonical `OperatorRole` enum's `->value`, not the enum
 * itself — same reasoning as `PaymentView`/`NotificationView` (ADR-0046):
 * `OperatorRole` lives top-level, outside `Contracts/`, so typing a
 * Contracts DTO's public property with it would let a cross-module
 * consumer depend on that type through property access, invisibly to the
 * G3c guardrail.
 */
final readonly class OperatorSummaryView
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public string $role,
        public bool $mfaEnabled,
        public bool $revoked,
        public DateTimeImmutable $createdAt,
    ) {}
}
