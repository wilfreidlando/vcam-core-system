<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

/**
 * Keyed hash of a secret, separated by purpose so that the hash of a link
 * can never be replayed as the hash of a cookie.
 */
interface SecretHasher
{
    public function hash(string $secret, string $purpose): string;
}
