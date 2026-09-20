<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentCallbackSendOutcome;

/**
 * Delivers a `payment.status_changed` notification to a Payment's own
 * `callbackUrl`. A Domain port, not an Infrastructure concrete type
 * directly, so `Presentation\Jobs\DeliverPaymentCallbackJob` can depend
 * on it without reaching across layers (deptrac-layers.yaml: Presentation
 * may not depend on Infrastructure).
 */
interface PaymentCallbackSender
{
    public function send(Payment $payment): PaymentCallbackSendOutcome;
}
