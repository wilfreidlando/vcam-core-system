<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentActivitySummaryWindow
{
    public function __construct(
        public int $sinceHours,
    ) {}
}
