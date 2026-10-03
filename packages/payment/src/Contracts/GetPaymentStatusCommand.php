<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Environment;

final readonly class GetPaymentStatusCommand
{
    public function __construct(
        public string $paymentId,
        public string $requestingProjectId,
        // ADR-0060 — a sandbox key never sees a live payment (and vice
        // versa). Null = no environment restriction (operator console).
        public ?Environment $requestingEnvironment = null,
    ) {}
}
