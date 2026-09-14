<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

use DateTimeImmutable;

/**
 * One sensitive operation, ready to be recorded.
 *
 * This module has no business rule of its own — it records what happened, it
 * does not decide what deserves recording (that judgement belongs to the
 * caller). So there is no separate `Domain/` layer here: this DTO is the
 * module's entire model, and it is defined directly in `Contracts/` because
 * that is what every consumer needs (see ADR-0029). A `Domain/` layer is
 * worth adding the day a real rule appears — a retention policy, a redaction
 * rule — not before.
 */
final readonly class AuditEntry
{
    /**
     * @param string $actor Who did it: "operator:<uuid>", "project:<uuid>", "service:<name>".
     * @param string $action What happened, e.g. "api_key.issued".
     * @param string $resource What it happened to, e.g. "project:<uuid>".
     * @param array<string, mixed>|null $before State before the change, for mutations.
     * @param array<string, mixed>|null $after State after the change, for mutations.
     * @param array<string, mixed> $context Anything else worth keeping — never a secret or an unhashed credential.
     */
    public function __construct(
        public string $actor,
        public string $action,
        public string $resource,
        public ?array $before,
        public ?array $after,
        public array $context,
        public ?string $correlationId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
