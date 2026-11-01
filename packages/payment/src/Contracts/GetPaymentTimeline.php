<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0059 — the full journey of one payment, oldest first: API request,
 * provider exchanges, response, provider callbacks, status transitions,
 * wallet credit, published event, `callback_url` attempts. Read by the
 * admin console's "Parcours", which adds the webhook deliveries of each
 * published event (`Webhook\Contracts\GetWebhookDeliveryDetails`).
 */
interface GetPaymentTimeline
{
    /**
     * The scope is required (ADR-0069 §2): the journey of a payment the
     * account may not see is not readable.
     *
     * @return list<PaymentTimelineEntryView>
     */
    public function forPayment(string $paymentId, AccessScope $scope): array;
}
