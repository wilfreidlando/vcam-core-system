<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class RevokeApiKeyCommand
{
    public function __construct(
        public string $apiKeyId,
        // When given, the key must belong to this project (anti-enumeration:
        // same "not found" otherwise).
        public ?string $projectId = null,
    ) {}
}
