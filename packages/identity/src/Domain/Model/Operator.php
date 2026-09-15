<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\OperatorRole;
use DateTimeImmutable;

/**
 * A platform operator (spec technique §5.1, Plan 1) — distinct from the
 * Project/ApiKey machine-identity plane (`ApiKey`, Plan 2). Uses PHP's
 * native `password_hash()`/`password_verify()` (Argon2id) rather than any
 * framework helper, keeping this Domain class framework-free (G3b), the
 * same reasoning as `ApiKey::hash()`/`matchesSecret()` using native
 * `hash()`/`hash_equals()`.
 */
final class Operator
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        private readonly string $passwordHash,
        public readonly OperatorRole $role,
        private ?string $mfaSecret,
        private bool $mfaEnabled,
        private ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function hashPassword(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_ARGON2ID);
    }

    public function verifyPassword(string $candidatePassword): bool
    {
        return password_verify($candidatePassword, $this->passwordHash);
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function mfaEnabled(): bool
    {
        return $this->mfaEnabled;
    }

    public function mfaSecret(): ?string
    {
        return $this->mfaSecret;
    }

    /**
     * Stores a freshly generated TOTP secret, not yet confirmed — MFA
     * only becomes `enabled` once the operator proves possession of it
     * via `confirmMfa()`.
     */
    public function enrollMfa(string $secret): void
    {
        $this->mfaSecret = $secret;
        $this->mfaEnabled = false;
    }

    public function confirmMfa(): void
    {
        $this->mfaEnabled = true;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }
}
