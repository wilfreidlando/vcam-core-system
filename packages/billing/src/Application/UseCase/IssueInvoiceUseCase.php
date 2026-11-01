<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Service\InvoiceNumberAllocator;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Domain\Exception\InvalidInvoiceTransition;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0073 §1 — `POST /v1/invoices/{id}/issue`: the draft becomes a
 * document.
 *
 * Three things happen in one transaction:
 *
 * - the **number** is attributed, under the lock of the sequence of the
 *   (project, year) pair — no gap, ever (ADR-0094 §5);
 * - the **receivable** is written to the ledger — Débit
 *   `customer_receivable:<client>` / Crédit `revenue` (ADR-0073 §3);
 * - the **document** is frozen: from this instant, only its status and
 *   what has been paid of it move.
 *
 * The event is published in the same transaction as the state it
 * describes (G8/G8c).
 */
final readonly class IssueInvoiceUseCase implements IssueInvoice
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
        private BillingSettingsRepository $settings,
        private InvoiceLedger $ledger,
        private InvoiceNumberAllocator $allocator,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function issue(IssueInvoiceCommand $command): InvoiceView
    {
        return $this->unitOfWork->transactional(function () use ($command): InvoiceView {
            $now = $this->clock->now();

            $invoice = $this->invoices->findById($command->invoiceId);

            if ($invoice === null || $invoice->projectId !== $command->projectId) {
                throw InvoiceNotFound::becauseId($command->invoiceId);
            }

            if (! $invoice->isDraft()) {
                throw InvalidInvoiceTransition::becauseNotAllowed(
                    $invoice->status->value,
                    InvoiceStatus::Open->value,
                );
            }

            $settings = $this->settings->find($command->projectId);

            // ADR-0094 §5 — the year of the **issuance**, in the timezone
            // of the project.
            $year = (int) $now->setTimezone($settings->zone())->format('Y');

            $number = $this->allocator->allocate($command->projectId, $year, $settings->invoicePrefix);

            $invoice->issue($number, $now);

            // ADR-0073 §3 — the issuance is booked. The transaction keeps
            // its reference, so that the cancellation can counter-pass
            // **this** one.
            $invoice->attachLedgerTransaction($this->ledger->postIssuance($invoice));

            $invoice->version++;

            $this->invoices->save($invoice);

            $customer = $this->customers->findById($invoice->customerId);
            $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'invoice.issued',
                InvoiceEvents::payload($invoice, $customerRef),
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
