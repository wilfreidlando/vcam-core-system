<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

/**
 * TOTP (RFC 6238) generation/verification — mirrors `ApiKeySecretGenerator`
 * in shape (a Domain port implemented by an Infrastructure adapter wrapping
 * a real library, keeping the library itself out of Domain/Contracts).
 */
interface TotpProvider
{
    public function generateSecret(): string;

    /**
     * `$afterTimestep`, when given, rejects a code whose matching 30s
     * time step is not strictly newer than it — RFC 6238 §5.2's
     * anti-replay requirement: without it, a TOTP code observed once
     * (log leak, shoulder-surfing) stays valid for reuse across its
     * whole window (~90s with the library's default drift tolerance).
     * Returns the matched time step (to persist as the caller's new
     * `$afterTimestep` for next time) on success, `false` otherwise.
     */
    public function verify(string $secret, string $code, ?int $afterTimestep = null): int|false;

    /**
     * The `otpauth://` provisioning URI an authenticator app scans/imports.
     * This platform is API-only (no server-side QR rendering) — the caller
     * gets the secret and this URI as plain strings.
     */
    public function buildOtpauthUri(string $secret, string $email): string;

    /**
     * The current 6-digit TOTP code for the given secret.
     *
     * Used by the dev-only `admin:current-otp` console command to surface
     * a usable code in a terminal (no more grepping docker logs). The
     * operator's MFA secret stays on the server; this method never logs
     * it. Implementations MUST return the current window's code only.
     *
     * @param string $secret The base32 raw TOTP secret (decrypted).
     */
    public function currentCode(string $secret): string;
}
