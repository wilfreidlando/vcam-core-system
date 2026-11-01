<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

/**
 * Never carries the secret or its hash — a listing is a visibility read,
 * not a credential export. `prefix` is the only part of the key ever
 * shown again after issuance (S-07).
 */
final readonly class ApiKeySummaryView
{
    /**
     * @param list<string> $scopes
     * @param list<string> $allowedCidrs
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $prefix,
        public array $scopes,
        public bool $revoked,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt = null,
        public string $name = 'Clé principale',
        public array $allowedCidrs = [],
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $replacedByApiKeyId = null,
        public ?string $lastUsedIp = null,
        // Revoked, or past its expiry: it no longer authenticates anything.
        public bool $active = true,
    ) {}

    public function hasFullAccess(): bool
    {
        return in_array(ApiKeyScopes::WILDCARD, $this->scopes, true);
    }
}
