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
    /**
     * A fixed, precomputed Argon2id hash of an arbitrary value — never a
     * real operator's password. `verifyAgainstDummyHash()` runs the same
     * `password_verify()` cost against it when the caller has no
     * `Operator` to check against (unknown email), so the login endpoint
     * takes roughly the same time whether the email exists or not.
     * Without this, an unknown-email request returns immediately while a
     * known-email/wrong-password request pays the full Argon2id cost,
     * letting a timing side-channel enumerate which operator emails exist
     * (review finding, Lot 6 follow-up).
     */
    private const string DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QVdidWQweDRTQzRVcmdKLg$4d7UJRVl1P5YfLSyvsq6VSxchbrs9wwpdNUO5hCFcSo';

    public function __construct(
        public readonly string $id,
        public string $email,
        public string $name,
        private readonly string $passwordHash,
        public OperatorRole $role,
        private ?string $mfaSecret,
        private bool $mfaEnabled,
        private ?int $mfaLastVerifiedTimestep,
        private ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function hashPassword(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_ARGON2ID);
    }

    public static function verifyAgainstDummyHash(string $candidatePassword): void
    {
        password_verify($candidatePassword, self::DUMMY_PASSWORD_HASH);
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
     * The TOTP time step (30s window index) of the last code this
     * operator successfully verified — `null` until their first
     * successful verification. Passed back to `TotpProvider::verify()`
     * as `$afterTimestep` so a code cannot be replayed within its
     * validity window (RFC 6238 §5.2, review finding, Lot 6 follow-up).
     */
    public function mfaLastVerifiedTimestep(): ?int
    {
        return $this->mfaLastVerifiedTimestep;
    }

    public function recordMfaVerification(int $timestep): void
    {
        $this->mfaLastVerifiedTimestep = $timestep;
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
        $this->mfaLastVerifiedTimestep = null;
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

    /**
     * Mutates in place — mirrors `revoke()`/`enrollMfa()`'s style. Never
     * touches credentials (`passwordHash`) or MFA state; those are
     * separate actions with their own use cases.
     */
    public function updateProfile(string $email, string $name, OperatorRole $role): void
    {
        $this->email = $email;
        $this->name = $name;
        $this->role = $role;
    }
}
