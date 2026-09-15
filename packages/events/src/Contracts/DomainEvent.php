<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

use DateTimeImmutable;

/**
 * One fact worth telling other systems about (spec technique §12.2, e.g.
 * `wallet.credited`, `payment.completed`). Like `Core\Audit\Contracts\AuditEntry`,
 * this is the module's entire model for now — no separate Domain layer,
 * because the only rule this module currently enforces is "written in the
 * same transaction as the state it describes" (ADR-0032), not a business
 * rule about the event itself.
 */
final readonly class DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $type,
        public array $payload,
        public ?string $correlationId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
