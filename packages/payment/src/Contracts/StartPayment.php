<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

interface StartPayment
{
    public function start(StartPaymentCommand $command): PaymentView;
}
