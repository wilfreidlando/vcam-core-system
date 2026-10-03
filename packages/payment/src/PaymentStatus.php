<?php

declare(strict_types=1);

namespace Core\Payment;

/**
 * Canonical payment lifecycle states.
 *
 * Backed string enum so values are stable across wire formats (JSON,
 * webhook payloads, log lines) and language boundaries.
 *
 * Vocabulary per [ADR-0035](../../../../../docs/adr/0035-canonical-payment-status-canceled.md):
 *   pending     — accepted by the provider, awaiting customer action.
 *   processing  — provider confirmed the customer paid, awaiting
 *                 settlement / final webhook.
 *   succeeded   — settled; the ledger can credit the wallet.
 *   failed      — provider reported a definitive failure (network
 *                 failure, invalid request, provider outage longer than
 *                 the retry budget). Caller should NOT retry.
 *   expired     — the provider's session window closed before the
 *                 customer paid. Caller may start a new intent.
 *   refunded    — money was returned to the customer after a succeed.
 *   canceled    — user-initiated cancellation before the customer
 *                 paid. Distinct from `failed` (the customer didn't try
 *                 and fail; they explicitly walked away) and from
 *                 `expired` (no timer ran out). Reporting should not
 *                 degrade the conversion rate.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Canceled = 'canceled';

    /** Whether this status is terminal (no further transitions expected). */
    /**
     * ADR-0061 — a final status means the payment's outcome is settled:
     * the integrator can act on it (deliver the order on `succeeded`, offer
     * a new attempt otherwise) and no further payment transition will
     * happen. `succeeded` is final for the collection itself; the only
     * thing that can still follow it is a refund (`refunded`).
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Expired,
            self::Refunded,
            self::Canceled => true,
            self::Pending,
            self::Processing => false,
        };
    }

    /**
     * Kept for existing callers: identical to `isFinal()`.
     */
    public function isTerminal(): bool
    {
        return $this->isFinal();
    }

    /**
     * ADR-0061 — the only transitions the Core accepts. Anything else (a
     * late `pending` after `succeeded`, a `failed` after `succeeded`, any
     * change after `failed`/`expired`/`canceled`/`refunded`) is refused
     * and traced in the payment's journey — a delayed or replayed provider
     * callback can never move a settled payment backwards.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return false;
        }

        return in_array($next, self::nextStatuses($this), true);
    }

    /**
     * @return list<self>
     */
    public static function nextStatuses(self $from): array
    {
        return match ($from) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed, self::Expired, self::Canceled],
            self::Processing => [self::Succeeded, self::Failed, self::Expired, self::Canceled],
            self::Succeeded => [self::Refunded],
            self::Failed, self::Expired, self::Canceled, self::Refunded => [],
        };
    }
}
