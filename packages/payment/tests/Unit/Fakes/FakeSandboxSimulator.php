<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Application\Port\SandboxPaymentSimulator;
use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\PaymentStatus;

final class FakeSandboxSimulator implements SandboxPaymentSimulator
{
    public int $calls = 0;

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $this->calls++;

        return new PaymentReceipt(transactionRef: 'sbx_test_'.$this->calls, status: PaymentStatus::Pending, requiresAction: true, actionHint: 'SANDBOX');
    }
}
