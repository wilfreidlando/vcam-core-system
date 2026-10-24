<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

/**
 * ADR-0083 §2 — application-level encryption of personal data, with a key
 * dedicated to it (never `APP_KEY`) and versioned: the ciphertext names the
 * key version that produced it, several versions decrypt during a
 * rotation, and `needsReEncryption()` tells a module's re-encryption job
 * which rows are still on an old key. Persists nothing (G3d).
 */
interface PersonalDataCipher
{
    /** @return string `pii:v<version>:<payload>`, safe to store in a text column */
    public function encrypt(string $plaintext): string;

    /**
     * @throws UndecryptablePersonalData unknown key version, tampered or foreign value
     */
    public function decrypt(string $ciphertext): string;

    /** True when the value was encrypted with another key than the current one. */
    public function needsReEncryption(string $ciphertext): bool;
}
