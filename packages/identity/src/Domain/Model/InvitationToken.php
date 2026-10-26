<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0069 §4 — the one-shot credential an invited member activates
 * their console account with: **32 random bytes**, base64url-encoded,
 * kept only as a **SHA-256 hash**. The clear value exists for exactly
 * one response — the invitation's creation — and the token forgets it
 * the moment it is read (`revealPlainText()` clears the field).
 *
 * The lifetime travels with the token (ADR-0069 §4: 72 hours) because
 * "is this invitation still usable?" is a question about the credential
 * itself, not about the row that stores it.
 *
 * No framework type here (G3b): `random_bytes()` is the CSPRNG of PHP
 * itself, and `hash()`/`hash_equals()` are native — the same reasoning
 * as `Operator::hashPassword()` using native `password_hash()`.
 */
final class InvitationToken
{
    /** 32 bytes = 256 bits of entropy, before the base64url encoding. */
    public const int ENTROPY_BYTES = 32;

    /** ADR-0069 §4 — an activation token is valid for 72 hours. */
    public const int LIFETIME_SECONDS = 72 * 3600;

    /**
     * The clear value, readable exactly once. `null` once read — and
     * `null` for every token rebuilt from the database.
     */
    private ?string $plainText;

    public function __construct(
        private readonly string $hash,
        ?string $plainText,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $acceptedAt = null,
        private ?DateTimeImmutable $revokedAt = null,
    ) {
        $this->plainText = $plainText;
    }

    /**
     * A fresh token issued now, valid for the ADR's 72 hours.
     */
    public static function issue(DateTimeImmutable $now): self
    {
        return self::generate($now->modify('+'.self::LIFETIME_SECONDS.' seconds'));
    }

    /**
     * A fresh token: 32 bytes from the CSPRNG, base64url-encoded. Only
     * its SHA-256 hash is kept — the clear value is never persisted.
     */
    public static function generate(DateTimeImmutable $expiresAt): self
    {
        $plainText = self::encode(random_bytes(self::ENTROPY_BYTES));

        return new self(self::hashOf($plainText), $plainText, $expiresAt);
    }

    /**
     * Rebuilds a token from what the database holds — no clear value.
     */
    public static function restore(
        string $hash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $acceptedAt = null,
        ?DateTimeImmutable $revokedAt = null,
    ): self {
        return new self($hash, null, $expiresAt, $acceptedAt, $revokedAt);
    }

    /**
     * The lookup key of a clear token presented by the invitee. The
     * invitation is found by this hash, never by the clear value.
     */
    public static function hashOf(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function acceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * The clear value — readable ONCE. The second call returns `null`:
     * the plaintext must not outlive the response that carries it
     * (ADR-0069 §4, ADR-0081 S-07).
     */
    public function revealPlainText(): ?string
    {
        $plainText = $this->plainText;
        $this->plainText = null;

        return $plainText;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * An invitation is usable while it is neither accepted, nor revoked,
     * nor expired.
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired($now);
    }

    public function accept(DateTimeImmutable $at): void
    {
        $this->acceptedAt ??= $at;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    /**
     * Constant-time comparison of a presented clear token against the
     * stored hash.
     */
    public function matches(string $candidate): bool
    {
        return hash_equals($this->hash, self::hashOf($candidate));
    }

    /**
     * Base64url without padding — the encoding that survives a URL, a
     * mail client and a copy/paste without being mangled.
     */
    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
