<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Security;

use Core\Portal\Domain\Port\SecretHasher;
use RuntimeException;

/**
 * HMAC-SHA256 keyed by the application key, one derived key per purpose.
 */
final readonly class HmacSecretHasher implements SecretHasher
{
    public function __construct(private string $applicationKey) {}

    public function hash(string $secret, string $purpose): string
    {
        $key = str_starts_with($this->applicationKey, 'base64:')
            ? base64_decode(substr($this->applicationKey, 7), true)
            : $this->applicationKey;

        if ($key === false || $key === '') {
            throw new RuntimeException('The application key is not set: the portal cannot hash its secrets.');
        }

        return hash_hmac('sha256', $secret, hash_hmac('sha256', 'portal:'.$purpose, $key, true));
    }
}
