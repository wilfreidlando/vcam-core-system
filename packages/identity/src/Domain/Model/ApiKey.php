<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\Domain\Exception\ApiKeyAlreadyRotated;
use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use DateTimeImmutable;

/**
 * A server-to-server credential of a Project. A project holds several named
 * keys, each with its own scopes (ADR-0082, amending ADR-0066's single key),
 * an optional network restriction and an optional expiry. The secret itself
 * is never held here: only its hash, and the visible `prefix` used to look
 * the row up before verifying.
 */
final class ApiKey
{
    public const DEFAULT_NAME = 'Clé principale';

    public const MAX_NAME_LENGTH = 80;

    public const MAX_CIDRS = 20;

    /**
     * @param list<string> $scopes
     * @param list<string> $allowedCidrs canonical blocks (see `CidrBlock::toString()`)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $prefix,
        private readonly string $keyHash,
        private array $scopes,
        private ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $lastUsedAt = null,
        public readonly string $name = self::DEFAULT_NAME,
        private readonly array $allowedCidrs = [],
        private ?DateTimeImmutable $expiresAt = null,
        private ?string $replacedByApiKeyId = null,
        public readonly ?string $lastUsedIp = null,
    ) {}

    /**
     * @param list<string> $scopes
     * @param list<string> $allowedCidrs any accepted notation; stored canonical
     */
    public static function issue(
        string $id,
        string $projectId,
        string $prefix,
        string $secretHash,
        array $scopes,
        DateTimeImmutable $issuedAt,
        string $name = self::DEFAULT_NAME,
        array $allowedCidrs = [],
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidApiKeySettings::name();
        }

        if (count($allowedCidrs) > self::MAX_CIDRS) {
            throw InvalidApiKeySettings::tooManyCidrs(self::MAX_CIDRS);
        }

        if ($expiresAt !== null && $expiresAt <= $issuedAt) {
            throw InvalidApiKeySettings::expiryInThePast();
        }

        $blocks = array_values(array_unique(array_map(
            static fn (string $cidr): string => CidrBlock::parse($cidr)->toString(),
            $allowedCidrs,
        )));

        return new self(
            $id,
            $projectId,
            $prefix,
            $secretHash,
            array_values(array_unique($scopes)),
            null,
            $issuedAt,
            name: $name,
            allowedCidrs: $blocks,
            expiresAt: $expiresAt,
        );
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

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /** Authenticates a request: not revoked and not past its expiry. */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return ! $this->isRevoked() && ! $this->isExpiredAt($now);
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * ADR-0082 §4 — no restriction means any address; once restricted, an
     * unknown address is refused.
     */
    public function allowsOrigin(?string $ip): bool
    {
        if ($this->allowedCidrs === []) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        foreach ($this->allowedCidrs as $cidr) {
            if (CidrBlock::parse($cidr)->contains($ip)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function allowedCidrs(): array
    {
        return $this->allowedCidrs;
    }

    public function replacedByApiKeyId(): ?string
    {
        return $this->replacedByApiKeyId;
    }

    public function isReplaced(): bool
    {
        return $this->replacedByApiKeyId !== null;
    }

    /**
     * ADR-0082 §3 — guided rotation: the old key stays valid until
     * `$retireAt` (never later than an expiry it already had), so the
     * project can deploy the new one without an outage.
     */
    public function markReplacedBy(string $newApiKeyId, DateTimeImmutable $retireAt): void
    {
        if ($this->replacedByApiKeyId !== null) {
            throw ApiKeyAlreadyRotated::for($this->prefix);
        }

        $this->replacedByApiKeyId = $newApiKeyId;
        $this->expiresAt = $this->expiresAt === null ? $retireAt : min($this->expiresAt, $retireAt);
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
        // `*` is the wildcard — kept for compatibility (ADR-0082 §2: shown as
        // « accès total » with a warning). Same rule as
        // `OperatorRole::hasPermission()` and `ProjectContext::hasScope()`.
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
