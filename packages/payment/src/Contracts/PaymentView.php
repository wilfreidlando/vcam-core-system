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
        public string $channel,
        public string $status,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
        public bool $requiresAction,
        public ?string $actionHint,
        public ?string $paymentUrl,
        public ?string $errorMessage,
    ) {}
}
