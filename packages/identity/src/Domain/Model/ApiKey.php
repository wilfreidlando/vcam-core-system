<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Support\Environment;
use DateTimeImmutable;

/**
 * A server-to-server credential for a Project, scoped to one environment
 * (sandbox or live — spec technique §5.1). The secret itself is never held
 * here: only its hash, and the visible `prefix` used to look the row up
 * before verifying.
 */
final class ApiKey
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly Environment $environment,
        public readonly string $prefix,
        private readonly string $keyHash,
        private array $scopes,
        private ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param list<string> $scopes
     */
    public static function issue(
        string $id,
        string $projectId,
        Environment $environment,
        string $prefix,
        string $secretHash,
        array $scopes,
        DateTimeImmutable $issuedAt,
    ): self {
        return new self($id, $projectId, $environment, $prefix, $secretHash, $scopes, null, $issuedAt);
    }

    /**
     * Verifies a candidate secret against the stored hash. `hash_equals` is
     * timing-safe, which matters here: a naive `===` would let an attacker
     * recover the hash byte by byte from response-time differences.
     */
    public function matchesSecret(string $candidateSecret): bool
    {
        return hash_equals($this->keyHash, self::hash($candidateSecret));
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * For the repository to persist — never for a comparison. Verifying a
     * candidate secret goes through `matchesSecret()`, which is timing-safe;
     * comparing two hashes read out via this accessor would not be.
     */
    public function keyHash(): string
    {
        return $this->keyHash;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        // `*` is the wildcard — a key issued with `--scope='*'` (the
        // ergonomic shape every Core-issued key uses, see ADR-0044)
        // covers every concrete scope. Mirrors `OperatorRole::hasPermission()`
        // which already treats `*` the same way. Mirroring is on purpose:
        // a Project key and an Operator token are both "full-power
        // credentials scoped to one tenant" and should behave the same
        // way on a wildcard.
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
