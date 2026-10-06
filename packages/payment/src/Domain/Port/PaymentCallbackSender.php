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
    /**
     * POSTs the `payment.status_changed` event to the payment's `callback_url`,
     * in the same envelope as the webhook plane: `{id, type, data}`.
     *
     * @param array<string, mixed> $data
     */
    public function send(Payment $payment, string $eventId, array $data): PaymentCallbackSendOutcome;
}
