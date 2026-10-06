<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class SimulateSandboxPaymentCommand
{
    /**
     * @param string $status `succeeded`, `failed`, `expired` or `canceled`
     * @param string|null $projectId null when the payer simulates from the
     *                               hosted checkout page (no API key — the
     *                               signed URL is the credential)
     */
    public function __construct(
        public string $status,
        public ?string $paymentId = null,
        public ?string $transactionRef = null,
        public ?string $projectId = null,
        public ?string $reason = null,
    ) {}
}
