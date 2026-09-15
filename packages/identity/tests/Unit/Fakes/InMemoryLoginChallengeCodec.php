<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\LoginChallengeCodec;
use DateTimeImmutable;

final class InMemoryLoginChallengeCodec implements LoginChallengeCodec
{
    /** @var array<string, array{operatorId: string, purpose: string, expiresAt: DateTimeImmutable}> */
    private array $tokens = [];

    private int $next = 1;

    public function issue(string $operatorId, string $purpose, DateTimeImmutable $expiresAt): string
    {
        $token = 'challenge-'.$this->next++;

        $this->tokens[$token] = ['operatorId' => $operatorId, 'purpose' => $purpose, 'expiresAt' => $expiresAt];

        return $token;
    }

    public function verify(string $token, string $expectedPurpose): ?string
    {
        $entry = $this->tokens[$token] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry['purpose'] !== $expectedPurpose) {
            return null;
        }

        return $entry['operatorId'];
    }
}
