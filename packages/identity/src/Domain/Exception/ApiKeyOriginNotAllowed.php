<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * ADR-0082 §4 — the key is valid but restricted to network blocks that do
 * not include the caller's address. Raised only after the secret matched,
 * so it reveals nothing to someone who does not hold the key.
 */
final class ApiKeyOriginNotAllowed extends RuntimeException
{
    public function __construct(
        public readonly string $apiKeyId,
        public readonly string $apiKeyPrefix,
        public readonly string $projectId,
        public readonly ?string $clientIp,
    ) {
        parent::__construct('This API key may not be used from this network address.');
    }
}
