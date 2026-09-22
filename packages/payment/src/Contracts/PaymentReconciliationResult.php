<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * `statusBefore`/`statusAfter` are the canonical `PaymentStatus` enum's
 * `->value` (G3c — never the enum itself in `Contracts/`), same convention
 * as `PaymentView::$status`. When `changed` is `false`, `statusAfter` equals
 * `statusBefore` — the provider confirmed the Core's own record.
 */
final readonly class PaymentReconciliationResult
{
    public function __construct(
        public bool $changed,
        public string $statusBefore,
        public string $statusAfter,
    ) {}
}
