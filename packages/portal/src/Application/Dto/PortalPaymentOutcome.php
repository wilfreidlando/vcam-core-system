<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * What the customer sees after pressing "Payer".
 */
final readonly class PortalPaymentOutcome
{
    public function __construct(
        public string $invoiceId,
        public string $status,
        public bool $requiresAction,
        public ?string $paymentUrl,
        public ?string $actionHint,
    ) {}
}
