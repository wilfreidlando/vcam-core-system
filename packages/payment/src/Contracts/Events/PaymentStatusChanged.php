<?php

declare(strict_types=1);

namespace Core\Payment\Contracts\Events;

use InvalidArgumentException;

/**
 * Provider-reported status change for a payment the Core started.
 *
 * This is a **canonical event** — it carries only data that is safe to
 * publish across modules, free of provider vocabulary. Adapters
 * (MyCoolPay, MTN, CinetPay, …) translate the provider-specific
 * payload into one of these before publishing.
 *
 * Consumers (apps, future wallet/audit/notification modules) subscribe
 * via the existing `Core\Events\Integration\EventOutbox` — they do NOT
 * call back into the adapter for this. The adapter is stateless from
 * their POV.
 *
 * The `providerCode` lets a consumer disambiguate when multiple
 * providers are wired in parallel (e.g. MTN for Cameroon, CinetPay for
 * Côte d'Ivoire). The `appTransactionRef` is the caller's own reference
 * — used by consumers to join the event to their own state.
 *
 * `occurredAt` is the provider's reported timestamp (ISO 8601) when
 * the event was a webhook. When the event is reconstructed from a
 * polling `checkStatus()`, it's the moment the polling call observed
 * the change — close enough for reporting purposes.
 *
 * `confirmedAmountMinor` / `confirmedCurrency` (ADR-0077 §1): what the
 * provider says it actually collected, read by the adapter from its own
 * payload. `null` means the source could not confirm an amount (a
 * `checkStatus()` response, the simulator) — the payment is then settled
 * on its requested amount and traced as "amount not verified".
 */
final readonly class PaymentStatusChanged
{
    public function __construct(
        public string $providerCode,
        public string $transactionRef,
        public string $appTransactionRef,
        public \Core\Payment\PaymentStatus $status,
        public ?string $reason = null,
        public ?string $occurredAt = null,
        public ?int $confirmedAmountMinor = null,
        public ?string $confirmedCurrency = null,
    ) {}

    /**
     * Wire format for the outbox payload. Carries only scalars + enum
     * value — no VO instances, no nested objects, no closures. The
     * outbox stores JSON; the relay (Lot 4) rehydrates this back into
     * a `PaymentStatusChanged` on dispatch.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'provider_code' => $this->providerCode,
            'transaction_ref' => $this->transactionRef,
            'app_transaction_ref' => $this->appTransactionRef,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt,
            'confirmed_amount_minor' => $this->confirmedAmountMinor,
            'confirmed_currency' => $this->confirmedCurrency,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $statusRaw = $payload['status'] ?? null;
        if (! is_string($statusRaw)) {
            throw new InvalidArgumentException('PaymentStatusChanged payload is missing a string "status".');
        }
        $status = \Core\Payment\PaymentStatus::tryFrom($statusRaw);
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                'PaymentStatusChanged payload carries unknown status "%s".',
                $statusRaw,
            ));
        }

        return new self(
            providerCode: self::str($payload, 'provider_code'),
            transactionRef: self::str($payload, 'transaction_ref'),
            appTransactionRef: self::str($payload, 'app_transaction_ref'),
            status: $status,
            reason: self::optStr($payload, 'reason'),
            occurredAt: self::optStr($payload, 'occurred_at'),
            confirmedAmountMinor: is_int($payload['confirmed_amount_minor'] ?? null) ? $payload['confirmed_amount_minor'] : null,
            confirmedCurrency: self::optStr($payload, 'confirmed_currency'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function str(array $payload, string $key): string
    {
        $v = $payload[$key] ?? '';

        return is_string($v) ? $v : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optStr(array $payload, string $key): ?string
    {
        $v = $payload[$key] ?? null;

        return is_string($v) ? $v : null;
    }
}
