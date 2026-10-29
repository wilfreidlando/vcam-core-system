<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

use Core\Billing\Contracts\EntitlementsView;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\SubscriptionView;

/**
 * The first screen: where the customer stands, in one look.
 */
final readonly class PortalHome
{
    /**
     * @param list<SubscriptionView> $subscriptions
     * @param list<InvoiceView> $openInvoices
     * @param array<string, int> $dueByCurrency what is owed, per currency, in minor units
     * @param array<string, int> $paidLast12Months what was paid over the last twelve months, per currency
     * @param list<InvoiceView> $recentInvoices the latest invoices, newest first
     * @param string|null $nextDueAt the earliest due date among open invoices
     * @param string|null $nextRenewalAt the earliest end of a current period
     */
    public function __construct(
        public string $customerName,
        public array $subscriptions,
        public array $openInvoices,
        public array $dueByCurrency,
        public ?EntitlementsView $entitlements,
        public array $paidLast12Months = [],
        public array $recentInvoices = [],
        public ?string $nextDueAt = null,
        public ?string $nextRenewalAt = null,
    ) {}
}
