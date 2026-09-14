<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

/**
 * The result of issuing a key. `plaintextToken` exists only here, in memory,
 * for the caller to display or transmit once — nothing downstream of this
 * DTO ever sees it again; only its hash is persisted.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public string $apiKeyId,
        public string $prefix,
        public string $plaintextToken,
    ) {}
}
