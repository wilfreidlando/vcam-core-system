<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

use Core\Billing\Contracts\InvoicePaymentView;

/**
 * One payment of the history, with the invoice it settles.
 */
final readonly class PortalPaymentLine
{
    public function __construct(
        public InvoicePaymentView $payment,
        public string $invoiceId,
        public ?string $invoiceNumber,
    ) {}
}
