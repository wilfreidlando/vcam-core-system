<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoicePaymentViews;
use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\PayInvoiceCommand;
use Core\Billing\Contracts\PayInvoiceResult;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\InvoicePaymentStatus;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Payment\Contracts\PaymentPurpose;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0073 §2 — `POST /v1/invoices/{id}/pay`: the collection of an
 * invoice, online.
 *
 * Two things happen in one transaction: the payment is started, and the
 * **attempt** is recorded. The attempt is the link between the money
 * that moves in the `payment` module and the receivable this module
 * owns — ADR-0073 §2 : « Il enregistre `invoice_payments` dans la même
 * transaction que la création du paiement ».
 *
 * The intention given to the payment module is `invoice` (ADR-0076 §3,
 * ADR-0094 §9): the money lands on `collections`, **not** on `revenue`.
 * Crediting `revenue` here would count the same money twice — once as
 * the collection, once as the allocation onto the receivable.
 *
 * The trade-off of the single transaction is the one the `payment`
 * module already makes (see its `StartPaymentUseCase`): the provider is
 * called inside the transaction, so a rollback between the call and the
 * commit loses the payment row. The reconciliation of ADR-0078 is what
 * catches the gap; the `Idempotency-Key` of the request (G7) is what
 * keeps the caller from charging twice.
 */
final readonly class PayInvoiceUseCase implements PayInvoice
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoiceRepository $invoices,
        private InvoicePaymentRepository $payments,
        private CustomerRepository $customers,
        private StartPayment $startPayment,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function pay(PayInvoiceCommand $command): PayInvoiceResult
    {
        return $this->unitOfWork->transactional(function () use ($command): PayInvoiceResult {
            $now = $this->clock->now();

            $invoice = $this->invoices->findById($command->invoiceId);

            if ($invoice === null || $invoice->projectId !== $command->projectId) {
                throw InvoiceNotFound::becauseId($command->invoiceId);
            }

            // ADR-0094 §8 — a settled invoice is refused, not paid again.
            if ($invoice->isSettled()) {
                throw InvoiceNotPayable::becauseAlreadySettled($invoice->id);
            }

            // A draft carries no receivable; a void or a written-off one
            // is not due.
            if ($invoice->status !== InvoiceStatus::Open) {
                throw InvoiceNotPayable::becauseStatus($invoice->id, $invoice->status->value);
            }

            // ADR-0073 §2 — ONE attempt in progress per invoice: a
            // second one requires the previous to have failed or been
            // canceled (the base enforces it too).
            $pending = $this->payments->findPendingByInvoice($invoice->id);

            if ($pending !== null) {
                throw InvoiceNotPayable::becauseAttemptAlreadyPending($invoice->id);
            }

            $remaining = $invoice->remainingMinor();

            if ($remaining <= 0) {
                throw InvoiceNotPayable::becauseAlreadySettled($invoice->id);
            }

            $customer = $this->customers->findById($invoice->customerId);

            // The project's own customer record is the source of truth
            // for the payer's details (ADR-0070 §1); what the caller
            // sends only fills the blanks.
            $payment = $this->startPayment->start(new StartPaymentCommand(
                projectId: $invoice->projectId,
                channel: $command->channel,
                amount: $remaining,
                currency: $invoice->currency,
                reason: $invoice->number ?? ('Invoice '.$invoice->id),
                idempotencyKey: $command->idempotencyKey,
                customerPhoneNumber: $command->customerPhoneNumber ?? $this->phoneOf($customer),
                customerName: $command->customerName ?? $this->nameOf($customer),
                customerEmail: $command->customerEmail ?? $this->emailOf($customer),
                customerLang: $customer instanceof Customer ? $customer->locale : null,
                returnUrl: $command->returnUrl,
                // ADR-0076 §3, ADR-0094 §9 — the intention decides where
                // the money is booked: `collections`, to be allocated
                // onto the receivable by `billing`.
                purpose: PaymentPurpose::INVOICE,
            ));

            $attempt = new InvoicePayment(
                id: $this->identifier->generate(),
                invoiceId: $invoice->id,
                projectId: $invoice->projectId,
                paymentId: $payment->id,
                amountMinor: $remaining,
                currency: $invoice->currency,
                status: InvoicePaymentStatus::Pending,
                createdAt: $now,
                updatedAt: $now,
            );

            $this->payments->save($attempt);

            return new PayInvoiceResult(
                payment: InvoicePaymentViews::from($attempt),
                requiresAction: $payment->requiresAction,
                paymentUrl: $payment->paymentUrl,
                actionHint: $payment->actionHint,
            );
        });
    }

    private function phoneOf(?Customer $customer): ?string
    {
        return $customer instanceof Customer ? $customer->phone : null;
    }

    private function nameOf(?Customer $customer): ?string
    {
        return $customer instanceof Customer ? $customer->displayName : null;
    }

    private function emailOf(?Customer $customer): ?string
    {
        return $customer instanceof Customer ? $customer->email : null;
    }
}
