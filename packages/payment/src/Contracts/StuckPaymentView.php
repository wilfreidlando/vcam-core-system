<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use DateTimeImmutable;

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
        /**
         * When this payment last changed — how long it's actually sat
         * untouched, the detail an operator needs to judge whether a
         * reconciliation run is worth trusting (review finding:
         * "toutes les infos" — the preview used to show WHICH payments
         * would be checked but not WHY they look stuck). Nullable only
         * because `Domain\Model\Payment::$updatedAt` itself is (a
         * freshly-created, not-yet-persisted Payment has none) — every
         * row `findStuckSince()` returns is already persisted, so this
         * is effectively always set in practice.
         */
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
