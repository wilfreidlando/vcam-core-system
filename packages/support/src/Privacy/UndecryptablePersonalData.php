<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

use RuntimeException;

/** Never carries the ciphertext or any part of the plaintext. */
final class UndecryptablePersonalData extends RuntimeException
{
    public static function unknownVersion(string $version): self
    {
        return new self(sprintf('Personal data encrypted with key version "%s", which is not configured (PII_ENCRYPTION_KEYS).', $version));
    }

    public static function malformed(): self
    {
        return new self('Value is not personal data encrypted by this system.');
    }

    public static function tampered(): self
    {
        return new self('Personal data failed authentication: wrong key or altered value.');
    }
}
