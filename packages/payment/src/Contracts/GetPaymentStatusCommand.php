<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class GetPaymentStatusCommand
{
    public function __construct(
        public string $paymentId,
        public string $requestingProjectId,
    ) {}
}
