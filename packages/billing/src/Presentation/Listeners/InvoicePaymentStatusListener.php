<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Listeners;

use Core\Billing\Application\UseCase\AllocateInvoicePaymentUseCase;
use Core\Billing\Application\UseCase\FailInvoicePaymentUseCase;
use Core\Events\Contracts\OutboxEventPublished;

/**
 * ADR-0073 §2 — `billing` learns that a payment settled by consuming
 * the `payment.status_changed` event of the outbox.
 *
 * The relay is the **only** physical reader of the outbox (ADR-0040
 * §1): this listener subscribes to the republication, never to the table
 * itself.
 *
 * It finds its own attempt by `payment_id` — the payment module knows
 * nothing of the invoices (ADR-0073 §Alternatives : « Laisser `payment`
 * connaître les factures. Rejeté »).
 *
 * The listener is **synchronous**: the relay dispatches outside any
 * transaction, and the allocation is a single transaction of its own.
 * At-least-once delivery is expected (ADR-0040 §1) — the conditional
 * transitions make a replay a no-op (ADR-0073 §2).
 */
final readonly class InvoicePaymentStatusListener
{
    public function __construct(
        private AllocateInvoicePaymentUseCase $allocate,
        private FailInvoicePaymentUseCase $fail,
    ) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;

        if ($event->type !== 'payment.status_changed') {
            return;
        }

        $payload = $event->payload;

        $paymentId = $payload['payment_id'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($paymentId) || ! is_string($status)) {
            return;
        }

        // ADR-0077 §1 — what the provider confirmed it collected. `null`
        // means the source confirmed no amount.
        $confirmedAmount = is_int($payload['confirmed_amount'] ?? null)
            ? $payload['confirmed_amount']
            : null;

        $confirmedCurrency = is_string($payload['confirmed_currency'] ?? null)
            ? $payload['confirmed_currency']
            : null;

        if ($status === 'succeeded') {
            $this->allocate->allocate($paymentId, $confirmedAmount, $confirmedCurrency);

            return;
        }

        // The other final states: the attempt did not settle.
        if (in_array($status, ['failed', 'expired', 'canceled'], true)) {
            $reason = $payload['failure_reason'] ?? null;

            $this->fail->fail(
                $paymentId,
                is_string($reason) && $reason !== '' ? $status.': '.$reason : $status,
            );
        }
    }
}
