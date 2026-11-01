<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0073 §3 — `POST /v1/invoices/{id}/void`: the invoice is
 * cancelled before any payment.
 *
 * The issuance is **counter-passed** — the receivable leaves the ledger
 * the way it came — and the document keeps its number: a cancelled
 * invoice stays in the series of the project (ADR-0094 §5).
 *
 * A void is refused when something has already been received: the money
 * would have to be given back, and that is a refund, not a void.
 */
final readonly class VoidInvoiceUseCase implements VoidInvoice
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
        private InvoiceLedger $ledger,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function void(VoidInvoiceCommand $command): InvoiceView
    {
        return $this->unitOfWork->transactional(function () use ($command): InvoiceView {
            $now = $this->clock->now();

            $invoice = $this->invoices->findById($command->invoiceId);

            if ($invoice === null || $invoice->projectId !== $command->projectId) {
                throw InvoiceNotFound::becauseId($command->invoiceId);
            }

            $invoice->void($command->reason, $now);

            // ADR-0073 §3 — the counter-passation of the issuance.
            $this->ledger->postVoid($invoice);

            $invoice->version++;

            $this->invoices->save($invoice);

            $customer = $this->customers->findById($invoice->customerId);
            $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'invoice.voided',
                InvoiceEvents::payload($invoice, $customerRef, $command->reason),
                $command->correlationId,
                $now,
            ));

            return InvoiceViews::from(
                $invoice,
                $customerRef,
                $this->lines->listByInvoice($invoice->id),
            );
        });
    }
}
