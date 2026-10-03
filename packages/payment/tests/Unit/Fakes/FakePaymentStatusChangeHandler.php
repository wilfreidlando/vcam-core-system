<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Throwable;

final class FakePaymentStatusChangeHandler implements PaymentStatusChangeHandler
{
    /** @var list<PaymentStatusChanged> */
    public array $handled = [];

    /**
     * Models a non-provider failure inside the handler itself — e.g. the
     * persistence/unique-constraint race a missing row lock used to
     * allow (review finding, Lot 6 follow-up) — to prove the caller's
     * exception handling doesn't assume every failure here is
     * provider-shaped.
     */
    public ?Throwable $handleException = null;

    public function handle(PaymentStatusChanged $event, string $source = 'provider_callback'): void
    {
        if ($this->handleException !== null) {
            throw $this->handleException;
        }

        $this->handled[] = $event;
    }
}
