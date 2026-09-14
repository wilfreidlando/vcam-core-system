<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Model\GeneratedApiKeySecret;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;
use Core\Support\Environment;

/**
 * `random_bytes()` — a CSPRNG, not `rand()`/`mt_rand()`. A predictable secret
 * would make every credential this issues guessable.
 */
final class RandomApiKeySecretGenerator implements ApiKeySecretGenerator
{
    private const PREFIX_ENTROPY_BYTES = 6; // -> 8 base62 characters

    private const SECRET_ENTROPY_BYTES = 32; // -> 256 bits

    public function generate(Environment $environment): GeneratedApiKeySecret
    {
        $prefix = 'pk_'.$environment->value.'_'.$this->randomBase62(self::PREFIX_ENTROPY_BYTES);
        $secret = $this->randomBase64Url(self::SECRET_ENTROPY_BYTES);

        return new GeneratedApiKeySecret($prefix, $secret);
    }

    /**
     * @param positive-int $bytes
     */
    private function randomBase62(int $bytes): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random = random_bytes($bytes);
        $result = '';

        foreach (str_split($random) as $byte) {
            $result .= $alphabet[ord($byte) % strlen($alphabet)];
        }

        return $result;
    }

    /**
     * @param positive-int $bytes
     */
    private function randomBase64Url(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
