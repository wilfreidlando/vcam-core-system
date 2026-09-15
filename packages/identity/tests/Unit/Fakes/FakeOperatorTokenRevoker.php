<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\OperatorTokenRevoker;

final class FakeOperatorTokenRevoker implements OperatorTokenRevoker
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function register(string $plaintextToken, string $operatorId): void
    {
        $this->tokens[$plaintextToken] = $operatorId;
    }

    public function revoke(string $plaintextToken): ?string
    {
        $operatorId = $this->tokens[$plaintextToken] ?? null;
        unset($this->tokens[$plaintextToken]);

        return $operatorId;
    }
}
