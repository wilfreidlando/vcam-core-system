<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * A narrow preview row for an operator scanning stuck payments before
 * triggering reconciliation — deliberately smaller than `PaymentView`
 * (no `requiresAction`/`actionHint`/`paymentUrl`/`errorMessage`/
 * `callbackSecret`): this view exists to let an operator see which
 * transactions will actually be touched, not to reproduce the full
 * payment detail.
 */
final readonly class StuckPaymentView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $channel,
        public string $status,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
    ) {}
}
