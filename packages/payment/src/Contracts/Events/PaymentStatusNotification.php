<?php

declare(strict_types=1);

namespace Core\Payment\Contracts\Events;

use InvalidArgumentException;

/**
 * ADR-0061 — the ONE public shape of a payment status change, as seen by
 * integrators: the `data` of the `payment.status_changed` event, delivered
 * identically to registered webhook endpoints and to a payment's own
 * `callback_url`. Only what an integrator needs to act — never the
 * internal provider code or provider transaction reference (ADR-0038).
 *
 * Distinct from `PaymentStatusChanged`, the INBOUND command a provider
 * callback or a reconciliation hands to the Core.
 */
final readonly class PaymentStatusNotification
{
    /** The exact keys of `toPayload()` — the published contract (OpenAPI `PaymentStatusChangedData`). */
    public const FIELDS = ['payment_id', 'reference', 'status', 'is_final', 'amount', 'currency', 'failure_reason', 'occurred_at'];

    public function __construct(
        public string $paymentId,
        public string $reference,
        public string $status,
        public bool $isFinal,
        public int $amount,
        public string $currency,
        public ?string $failureReason,
        public string $occurredAt,
    ) {}

    /**
     * @return array{payment_id: string, reference: string, status: string, is_final: bool, amount: int, currency: string, failure_reason: string|null, occurred_at: string}
     */
    public function toPayload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'reference' => $this->reference,
            'status' => $this->status,
            'is_final' => $this->isFinal,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failure_reason' => $this->failureReason,
            'occurred_at' => $this->occurredAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $paymentId = $payload['payment_id'] ?? null;
        if (! is_string($paymentId) || $paymentId === '') {
            throw new InvalidArgumentException('payment.status_changed payload is missing payment_id.');
        }

        $amount = $payload['amount'] ?? 0;
        $failure = $payload['failure_reason'] ?? null;

        return new self(
            paymentId: $paymentId,
            reference: is_string($payload['reference'] ?? null) ? $payload['reference'] : '',
            status: is_string($payload['status'] ?? null) ? $payload['status'] : '',
            isFinal: ($payload['is_final'] ?? false) === true,
            amount: is_int($amount) ? $amount : 0,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : '',
            failureReason: is_string($failure) ? $failure : null,
            occurredAt: is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : '',
        );
    }
}
