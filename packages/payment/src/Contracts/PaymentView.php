<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * The read model returned to a caller — never `Core\Payment\Domain\Model\Payment`,
 * which stays internal (G3c). Carries only canonical vocabulary: no
 * `provider_code`, no raw provider fields.
 *
 * `$status` is the canonical `PaymentStatus` enum's `->value`, not the enum
 * itself (ADR-0046, follow-up to ADR-0045 §3): `PaymentStatus` lives
 * top-level, outside `Contracts/`, precisely so it stays out of reach of
 * every OTHER module (G3c) — a Contracts DTO typing a public property with
 * it would let a cross-module consumer depend on that type through
 * property access without ever naming it, invisibly to the guardrail that
 * exists to prevent exactly that. Same reasoning already applied to the
 * command-side DTOs (`ListPaymentsCommand::$status: ?string`); this makes
 * the read side consistent with it.
 */
final readonly class PaymentView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $channel,
        public string $status,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
        public bool $requiresAction,
        public ?string $actionHint,
        public ?string $paymentUrl,
        public ?string $errorMessage,
        /**
         * The same value the caller sent as its `Idempotency-Key` header
         * at initiation (ADR-0025/G7) — reused, unmodified, as this
         * payment's `app_transaction_ref` in the `payment.status_changed`
         * webhook payload (`Contracts\Events\PaymentStatusChanged`).
         * Review finding, user-directed: the webhook already echoed the
         * caller's own reference back, but `GET /v1/payments/{id}` (the
         * documented fallback truth source when a webhook is missed)
         * didn't — a caller polling status instead of trusting the
         * webhook had no way to confirm which of ITS OWN transactions it
         * was looking at.
         */
        public string $idempotencyKey,
        /**
         * The caller's own server-to-server notification target, as
         * supplied at initiation — never the secret that signs deliveries
         * to it (`$callbackSecret`, shown once, at creation only). Review
         * finding: a caller had no way to read back which callback_url it
         * registered for a payment, not even via `GET /v1/payments/{id}`.
         */
        public ?string $callbackUrl,
        /**
         * Populated ONLY by `StartPaymentUseCase::start()`, on the
         * response that creates the payment — the one-shot-secret
         * pattern (API keys, webhook endpoint secrets). Every other
         * producer of a `PaymentView` (`GetPaymentStatusUseCase`,
         * `ListPaymentsUseCase`) MUST pass `null` here; it is never
         * re-derivable from stored state on purpose.
         */
        public ?string $callbackSecret = null,
    ) {}
}
