<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\ManualReceiptViews;
use Core\Billing\Contracts\ManualReceiptView;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Model\ManualReceipt;
use Core\Billing\Domain\Model\ManualReceiptMethod;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Domain\Port\ManualReceiptRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;
use Exception;

/**
 * ADR-0073 §4, ADR-0094 §13 — an offline collection.
 *
 * The only « activation manuelle » the Core allows: the receipt says
 * what arrived, how much and under which reference, and it is booked
 * against the customer's receivable — Débit `manual_receipts:<moyen>` /
 * Crédit `customer_receivable:<client>` (ADR-0073 §3).
 *
 * Above the project's threshold (`manual_receipt_approval_threshold
 * _minor`), the receipt waits for a **second** console account and
 * books nothing yet; below it, it becomes a bookkeeping fact at the
 * recording (ADR-0094 §13).
 */
final readonly class RecordManualReceiptUseCase implements RecordManualReceipt
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoiceRepository $invoices,
        private ManualReceiptRepository $receipts,
        private BillingSettingsRepository $settings,
        private CustomerRepository $customers,
        private InvoiceLedger $ledger,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function record(RecordManualReceiptCommand $command): ManualReceiptView
    {
        return $this->unitOfWork->transactional(function () use ($command): ManualReceiptView {
            $now = $this->clock->now();

            $invoice = $this->invoices->findById($command->invoiceId);

            if ($invoice === null || $invoice->projectId !== $command->projectId) {
                throw InvoiceNotFound::becauseId($command->invoiceId);
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                throw InvoiceNotPayable::becauseStatus($invoice->id, $invoice->status->value);
            }

            if ($invoice->isSettled()) {
                throw InvoiceNotPayable::becauseAlreadySettled($invoice->id);
            }

            if ($command->amountMinor <= 0) {
                throw InvoiceNotPayable::becauseNothingReceived();
            }

            // ADR-0073 §3 — the money of an invoice is in the invoice's
            // own currency.
            if (strtoupper($command->currency) !== strtoupper($invoice->currency)) {
                throw InvoiceNotPayable::becauseCurrencyMismatch($invoice->id, $command->currency);
            }

            // ADR-0094 §10 — a receipt that covers more than what is due
            // is never imputed: the invoice would be over-paid.
            if ($command->amountMinor > $invoice->remainingMinor()) {
                throw InvoiceNotPayable::becauseAboveRemaining(
                    $invoice->id,
                    $command->amountMinor,
                    $invoice->remainingMinor(),
                );
            }

            $method = ManualReceiptMethod::tryFrom($command->method);

            if ($method === null) {
                throw InvoiceNotPayable::becauseUnknownMethod($command->method);
            }

            $receipt = new ManualReceipt(
                id: $this->identifier->generate(),
                projectId: $command->projectId,
                invoiceId: $invoice->id,
                method: $method,
                reference: $command->reference,
                amountMinor: $command->amountMinor,
                currency: $invoice->currency,
                receivedAt: $this->receivedAt($command->receivedAt),
                recordedBy: $command->recordedBy,
                createdAt: $now,
                updatedAt: $now,
                evidenceRef: $command->evidenceRef,
                note: $command->note,
            );

            $threshold = $this->settings->find($command->projectId)->manualReceiptApprovalThresholdMinor;

            // ADR-0094 §13 — above the threshold, the receipt waits for a
            // second account: nothing is booked yet.
            if ($command->amountMinor > $threshold) {
                $this->receipts->save($receipt);
                $this->publishRecorded($receipt, $invoice, $now);

                return ManualReceiptViews::from($receipt);
            }

            // Below the threshold: it becomes a bookkeeping fact at the
            // recording.
            $transactionId = $this->ledger->postManualReceipt($receipt, $invoice);
            $receipt->accountAtRecording($now, $transactionId);
            $this->receipts->save($receipt);

            $invoice->credit($command->amountMinor, $now);
            $invoice->version++;
            $this->invoices->save($invoice);

            $this->publishRecorded($receipt, $invoice, $now);
            $this->publishPaid($invoice, $now);

            return ManualReceiptViews::from($receipt);
        });
    }

    /**
     * ADR-0073 §4 — the receipt was recorded: the event says so.
     */
    private function publishRecorded(ManualReceipt $receipt, Invoice $invoice, DateTimeImmutable $now): void
    {
        $customer = $this->customers->findById($invoice->customerId);
        $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $invoice->projectId,
            'invoice.receipt_recorded',
            [
                ...InvoiceEvents::payload($invoice, $customerRef),
                'receipt_id' => $receipt->id,
                'method' => $receipt->method->value,
                'reference' => $receipt->reference,
                'amount_minor' => $receipt->amountMinor,
                'receipt_status' => $receipt->status->value,
            ],
            null,
            $now,
        ));
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

    /**
     * The date the money arrived — the operator says it, and the Core
     * reads it.
     */
    private function receivedAt(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw InvoiceNotPayable::becauseInvalidDate($value);
        }
    }
}
