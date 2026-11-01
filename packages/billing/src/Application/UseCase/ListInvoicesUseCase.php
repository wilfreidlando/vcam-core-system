<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\InvoicePage;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §1 — `GET /v1/invoices?customer_ref=&status=&cursor=`: the
 * invoices of one project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see, and an explicit
 * project outside the scope yields an empty page rather than another
 * tenant's invoices.
 */
final readonly class ListInvoicesUseCase implements ListInvoices
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
    ) {}

    public function list(ListInvoicesCommand $command, AccessScope $scope): InvoicePage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new InvoicePage([]);
        }

        $customerId = null;

        if ($command->customerRef !== null && $command->customerRef !== '') {
            // The reference is the project's own key: it is resolved to
            // the customer it names, and a reference nobody answers
            // yields an empty page — never another tenant's invoices.
            $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

            if ($customer === null) {
                return new InvoicePage([]);
            }

            $customerId = $customer->id;
        }

        $page = $this->invoices->listByProject(
            $command->projectId,
            $command->status,
            $customerId,
            $command->cursor,
            $command->limit,
        );

        $items = array_map(
            fn (Invoice $invoice): InvoiceView => InvoiceViews::from(
                $invoice,
                $this->customerRefOf($invoice),
                $this->lines->listByInvoice($invoice->id),
            ),
            $page['items'],
        );

        return new InvoicePage($items, $page['nextCursor']);
    }

    /**
     * The customer is named by the reference the project knows it by —
     * never by an id that would say nothing (ADR-0070 §1).
     */
    private function customerRefOf(Invoice $invoice): string
    {
        $customer = $this->customers->findById($invoice->customerId);

        return $customer instanceof Customer ? $customer->externalRef : '';
    }
}
