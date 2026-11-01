<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

/**
 * A read over the payment activity of a window — for the admin dashboard.
 * Read-only, no row-level data leaves this port: only counts.
 */
interface PaymentActivitySummary
{
    /**
     * The scope is required (ADR-0069 §2): only the payments of the
     * projects the console account may see are counted. The filter is
     * applied in the aggregation query itself, so the payments of another
     * tenant are never counted, let alone materialised.
     */
    public function summarize(PaymentActivitySummaryWindow $window, AccessScope $scope): PaymentActivitySummaryView;
}
