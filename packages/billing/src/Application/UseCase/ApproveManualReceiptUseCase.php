<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Service\SubscriptionSupplementActivator;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\ManualReceiptViews;
use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ApproveManualReceiptCommand;
use Core\Billing\Contracts\ManualReceiptView;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Exception\ManualReceiptApprovalRefused;
use Core\Billing\Domain\Exception\ManualReceiptNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Domain\Port\ManualReceiptRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0073 §4, ADR-0094 §13 — the second pair of eyes on an offline
 * collection.
 *
 * The account that recorded the receipt may validate it: ADR-0095
 * withdrew the four eyes of ADR-0073 §4. What the Core asks for is a
 * deliberate act, recorded and audited — the validation is what turns a
 * pending receipt into a bookkeeping fact, Débit
 * `manual_receipts:<moyen>` / Crédit `customer_receivable:<client>`
 * (ADR-0073 §3).
 *
 * A refusal (`approved = false`) books nothing and leaves the trail.
 */
final readonly class ApproveManualReceiptUseCase implements ApproveManualReceipt
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private ManualReceiptRepository $receipts,
        private InvoiceRepository $invoices,
        private CustomerRepository $customers,
        private InvoiceLedger $ledger,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private SubscriptionSupplementActivator $activator,
    ) {}

    public function approve(ApproveManualReceiptCommand $command): ManualReceiptView
    {
        return $this->unitOfWork->transactional(function () use ($command): ManualReceiptView {
            $now = $this->clock->now();

            $receipt = $this->receipts->findById($command->receiptId);

            if ($receipt === null || $receipt->projectId !== $command->projectId) {
                throw ManualReceiptNotFound::becauseId($command->receiptId);
            }

            if (! $receipt->isPending()) {
                throw ManualReceiptApprovalRefused::becauseNotPending(
                    $receipt->id,
                    $receipt->status->value,
                );
            }

            // ADR-0095 — one console account is enough to validate an
            // offline receipt, the author included: the four eyes of
            // ADR-0073 §4 are withdrawn. What the Core asks for is a
            // deliberate act, recorded and audited.

            // The refusal: nothing is booked, the trail stays.
            if (! $command->approved) {
                $receipt->reject($command->approver, $now);
                $this->receipts->save($receipt);

                return ManualReceiptViews::from($receipt);
            }

            $invoice = $this->invoices->findById($receipt->invoiceId);

            if ($invoice === null) {
                throw InvoiceNotFound::becauseId($receipt->invoiceId);
            }

            // ADR-0073 §3 — a receipt is approved against an invoice that
            // is still waiting for its money. Between the recording and
            // the approval the invoice may have been annulled or written
            // off: the receipt then waits on the operator, it is not
            // imputed on a dead invoice.
            if ($invoice->status !== InvoiceStatus::Open) {
                throw InvoiceNotPayable::becauseStatus($invoice->id, $invoice->status->value);
            }

            // ADR-0094 §10 — what is still due may have shrunk since the
            // receipt was recorded: a receipt that no longer fits is
            // refused, not partially imputed.
            if ($receipt->amountMinor > $invoice->remainingMinor()) {
                throw InvoiceNotPayable::becauseAboveRemaining(
                    $invoice->id,
                    $receipt->amountMinor,
                    $invoice->remainingMinor(),
                );
            }

            $transactionId = $this->ledger->postManualReceipt($receipt, $invoice);
            $receipt->approve($command->approver, $now, $transactionId);
            $this->receipts->save($receipt);

            $invoice->credit($receipt->amountMinor, $now);
            $invoice->version++;
            $this->invoices->save($invoice);

            $this->publishPaid($invoice, $now);

            // ADR-0117 §4 — the money arrived: the supplements the
            // invoice carries grant their allowance from this instant, in
            // this very transaction.
            $this->activator->settle($invoice);

            return ManualReceiptViews::from($receipt);
        });
    }

    /**
     * ADR-0073 §6 — the invoice is settled: the event says so.
     */
    private function publishPaid(Invoice $invoice, DateTimeImmutable $now): void
    {
        if ($invoice->status->value !== 'paid') {
            return;
        }

        $customer = $this->customers->findById($invoice->customerId);
        $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $invoice->projectId,
            'invoice.paid',
            InvoiceEvents::payload($invoice, $customerRef),
            null,
            $now,
        ));
    }
}
