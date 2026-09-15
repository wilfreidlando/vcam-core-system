<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\TotpProvider;

final class FixedTotpProvider implements TotpProvider
{
    public const string VALID_CODE = '123456';

    public function generateSecret(): string
    {
        return 'FIXED-TOTP-SECRET';
    }

    public function verify(string $secret, string $code): bool
    {
        return $code === self::VALID_CODE;
    }

    public function buildOtpauthUri(string $secret, string $email): string
    {
        return 'otpauth://totp/Test:'.$email.'?secret='.$secret;
    }
}
