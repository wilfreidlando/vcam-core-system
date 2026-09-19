<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * A global (cross-project) read for the admin dashboard — the one
 * deliberate exception to `ListPayments`'s project-scoping (ADR-0045):
 * an operator console overview needs a platform-wide count, never
 * individual payment rows. Read-only, no row-level data leaves this
 * port.
 */
interface PaymentActivitySummary
{
    public function summarize(PaymentActivitySummaryWindow $window): PaymentActivitySummaryView;
}
