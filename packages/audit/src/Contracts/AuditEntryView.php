<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

use DateTimeImmutable;

/**
 * The read-side counterpart of `AuditEntry` (the write-side DTO). Kept
 * separate rather than reused: this one carries the row `id` (useful for
 * stable citation across pages), which the write path never needed.
 */
final readonly class AuditEntryView
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public int $id,
        public string $actor,
        public string $action,
        public string $resource,
        public ?array $before,
        public ?array $after,
        public ?array $context,
        public ?string $correlationId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
