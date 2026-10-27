<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MarkInvoiceUncollectibleCommand;
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
 * ADR-0073 §3 — the invoice becomes a bad debt.
 *
 * The receivable leaves the customer and lands on `bad_debt` (Débit
 * `bad_debt` / Crédit `customer_receivable:<client>`): the ledger says
 * what the document says.
 *
 * A write-off is an **act of the operator**, never an effect of the time
 * (ADR-0094 §12): it carries a motive, it is audited, and it is
 * published in the same transaction as the state it describes.
 */
final readonly class MarkInvoiceUncollectibleUseCase implements MarkInvoiceUncollectible
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

    public function markUncollectible(MarkInvoiceUncollectibleCommand $command): InvoiceView
    {
        return $this->unitOfWork->transactional(function () use ($command): InvoiceView {
            $now = $this->clock->now();

            $invoice = $this->invoices->findById($command->invoiceId);

            if ($invoice === null || $invoice->projectId !== $command->projectId) {
                throw InvoiceNotFound::becauseId($command->invoiceId);
            }

            $invoice->markUncollectible($now);

            // ADR-0073 §3 — the receivable becomes a bad debt.
            $this->ledger->postUncollectible($invoice);

            $invoice->version++;

            $this->invoices->save($invoice);

            $customer = $this->customers->findById($invoice->customerId);
            $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'invoice.uncollectible',
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
