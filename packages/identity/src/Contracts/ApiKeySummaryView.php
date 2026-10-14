<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

/**
 * Never carries the secret or its hash — a listing is a visibility read,
 * not a credential export. `prefix` is the only part of the key ever
 * shown again after issuance (same one-shot-secret convention as a
 * webhook endpoint's signing secret or a provider application's private
 * key).
 */
final readonly class ApiKeySummaryView
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $prefix,
        public array $scopes,
        public bool $revoked,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {}
}
