<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\TotpProvider;

final class FixedTotpProvider implements TotpProvider
{
    public const string VALID_CODE = '123456';

    /**
     * Auto-increments on every call — simulates a real clock advancing
     * between separate verification calls, so successive logins in the
     * same test (each a genuinely new attempt) each get a fresh,
     * strictly-newer time step and succeed, exactly like the real
     * `Google2faTotpProvider` would across two calls made seconds apart.
     */
    private int $timestep = 1_000;

    public function generateSecret(): string
    {
        return 'FIXED-TOTP-SECRET';
    }

    public function verify(string $secret, string $code, ?int $afterTimestep = null): int|false
    {
        if ($code !== self::VALID_CODE) {
            return false;
        }

        $this->timestep++;

        return $this->timestep;
    }

    public function buildOtpauthUri(string $secret, string $email): string
    {
        return 'otpauth://totp/Test:'.$email.'?secret='.$secret;
    }

    public function currentCode(string $secret): string
    {
        return self::VALID_CODE;
    }
}
