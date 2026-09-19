<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentActivitySummaryView
{
    public function __construct(
        public int $total,
        public int $succeeded,
    ) {}
}
