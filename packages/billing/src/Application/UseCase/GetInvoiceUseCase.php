<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §1 — `GET /v1/invoices/{id}`: the document, its lines and
 * what has been paid of it.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing and
 * the reading never cross the projects the caller may see. An invoice of
 * another project is **not found** — never told apart from one that does
 * not exist.
 */
final readonly class GetInvoiceUseCase implements GetInvoice
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
    ) {}

    public function get(GetInvoiceCommand $command, AccessScope $scope): ?InvoiceView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $invoice = $this->invoices->findById($command->invoiceId);

        if ($invoice === null || $invoice->projectId !== $command->projectId) {
            return null;
        }

        $customer = $this->customers->findById($invoice->customerId);

        return InvoiceViews::from(
            $invoice,
            $customer instanceof Customer ? $customer->externalRef : '',
            $this->lines->listByInvoice($invoice->id),
        );
    }
}
