<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0073 §2 — the life cycle of an attempt to collect an invoice
 * online.
 *
 * - `pending` — the payment is running; the invoice is not credited yet;
 * - `succeeded` — the payment settled **and** the amount was imputed on
 *   the invoice;
 * - `failed` — the payment did not settle;
 * - `canceled` — the attempt was abandoned by the caller.
 *
 * A `succeeded` attempt is an **imputed** one: an attempt whose money
 * arrived but whose amount did not match is never `succeeded` (ADR-0077
 * §2, ADR-0094 §10) — it opens a money exception instead.
 */
enum InvoicePaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * ADR-0073 §2 — only a pending attempt can still move: the
     * conditional transition `WHERE status = 'pending'` is what makes a
     * replay a no-op.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
