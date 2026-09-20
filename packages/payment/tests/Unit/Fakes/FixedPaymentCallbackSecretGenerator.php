<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Port\PaymentCallbackSecretGenerator;

final class FixedPaymentCallbackSecretGenerator implements PaymentCallbackSecretGenerator
{
    public function __construct(
        private readonly string $secret = 'fixed-callback-secret',
    ) {}

    public function generate(): string
    {
        return $this->secret;
    }
}
