<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

interface PaymentCallbackSecretGenerator
{
    public function generate(): string;
}
