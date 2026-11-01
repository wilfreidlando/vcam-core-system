<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class RevokeApiKeyCommand
{
    public function __construct(
        public string $apiKeyId,
        // ADR-0082 §3 — an immediate revocation always says why (audited).
        public string $reason,
        // When given, the key must belong to this project (anti-enumeration:
        // same "not found" otherwise).
        public ?string $projectId = null,
    ) {}
}
