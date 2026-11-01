<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Contracts\InvoiceView;

final readonly class PortalInvoiceDetail
{
    /**
     * @param list<InvoicePaymentView> $payments
     * @param list<string> $channels the rails offered for this invoice, already filtered to the routable ones
     */
    public function __construct(
        public InvoiceView $invoice,
        public array $payments,
        public bool $payable,
        public string $formNonce,
        public array $channels = [],
    ) {}
}
