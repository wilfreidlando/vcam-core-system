<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;

final class FakePaymentStatusChangeHandler implements PaymentStatusChangeHandler
{
    /** @var list<PaymentStatusChanged> */
    public array $handled = [];

    public function handle(PaymentStatusChanged $event): void
    {
        $this->handled[] = $event;
    }
}
