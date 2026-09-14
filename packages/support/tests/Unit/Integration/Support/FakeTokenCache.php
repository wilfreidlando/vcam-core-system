<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Support;

use Core\Support\Integration\Auth\Token;
use Core\Support\Integration\Auth\TokenCache;

/**
 * Test double for `TokenCache`. Holds tokens in a plain array.
 * Not used by the foundation's static-token auth providers today —
 * kept for future OAuth2 work.
 */
final class FakeTokenCache implements TokenCache
{
    /** @var array<string, Token> */
    private array $entries = [];

    public function get(string $key): ?Token
    {
        return $this->entries[$key] ?? null;
    }

    public function put(string $key, Token $token): void
    {
        $this->entries[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }
}
