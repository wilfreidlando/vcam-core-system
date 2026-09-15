<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Port\TotpProvider;
use PragmaRX\Google2FA\Google2FA;

final class Google2faTotpProvider implements TotpProvider
{
    private const string ISSUER = 'Enterprise Core System';

    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code) === true;
    }

    public function buildOtpauthUri(string $secret, string $email): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $email, $secret);
    }
}
