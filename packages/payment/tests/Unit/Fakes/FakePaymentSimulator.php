<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Application\Port\PaymentSimulator;
use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\PaymentStatus;

final class FakePaymentSimulator implements PaymentSimulator
{
    public int $calls = 0;

    public function __construct(
        private readonly bool $enabled = false,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $this->calls++;

        return new PaymentReceipt(transactionRef: 'sim_test_'.$this->calls, status: PaymentStatus::Pending, requiresAction: true, actionHint: 'SIMULATEUR');
    }
}
