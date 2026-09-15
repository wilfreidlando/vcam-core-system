<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\OperatorTokenResolver;

final class FakeOperatorTokenResolver implements OperatorTokenResolver
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function register(string $plaintextToken, string $operatorId): void
    {
        $this->tokens[$plaintextToken] = $operatorId;
    }

    public function resolve(string $plaintextToken): ?string
    {
        return $this->tokens[$plaintextToken] ?? null;
    }
}
