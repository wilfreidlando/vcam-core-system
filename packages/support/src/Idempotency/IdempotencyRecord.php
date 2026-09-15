<?php

declare(strict_types=1);

namespace Core\Support\Idempotency;

/**
 * A stored idempotency key's current state (ADR-0025). `completedAt`
 * distinguishes a finished request (safe to replay — return the cached
 * response) from one still in flight (`locked_at` set, not yet
 * completed — a concurrent request must be rejected, not raced).
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $requestHash,
        public bool $completed,
        public ?int $responseStatus,
        /** @var array<string, mixed>|null */
        public ?array $responseBody,
    ) {}
}
