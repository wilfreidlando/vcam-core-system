<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

/**
 * Cached OAuth access token for one provider.
 *
 * The `expiresAt` is a Unix timestamp (seconds). The auth provider compares
 * it against the clock before returning the cached token — tokens whose
 * `expiresAt` is in the past are not returned and the caller refreshes.
 */
final readonly class Token
{
    public function __construct(
        public string $value,
        public int $expiresAt,
    ) {}

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
