<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Port\LoginChallengeCodec;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * `Crypt::encryptString()` on a JSON payload — authenticated encryption
 * (AES-256-CBC + HMAC via Laravel's encrypter), so the token cannot be
 * forged or tampered with. No new table: the token itself carries
 * everything needed to verify it.
 */
final class EncryptedLoginChallengeCodec implements LoginChallengeCodec
{
    public function issue(string $operatorId, string $purpose, DateTimeImmutable $expiresAt): string
    {
        $payload = json_encode([
            'operator_id' => $operatorId,
            'purpose' => $purpose,
            'expires_at' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return Crypt::encryptString($payload);
    }

    public function verify(string $token, string $expectedPurpose): ?string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $operatorId = $payload['operator_id'] ?? null;
        $purpose = $payload['purpose'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (! is_string($operatorId) || ! is_string($purpose) || ! is_int($expiresAt)) {
            return null;
        }

        if ($purpose !== $expectedPurpose) {
            return null;
        }

        if ($expiresAt < time()) {
            return null;
        }

        return $operatorId;
    }
}
