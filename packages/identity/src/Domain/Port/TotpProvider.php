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

    public function verify(string $secret, string $code): bool;

    /**
     * The `otpauth://` provisioning URI an authenticator app scans/imports.
     * This platform is API-only (no server-side QR rendering) — the caller
     * gets the secret and this URI as plain strings.
     */
    public function buildOtpauthUri(string $secret, string $email): string;
}
