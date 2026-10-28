<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoicePaymentViews;
use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0073 §2, §6 — the collection that did not settle.
 *
 * The attempt is closed with the reason, and the invoice is told about
 * it (`invoice.payment_failed`). The conditional transition — `WHERE
 * status = 'pending'` — makes a replay a no-op (ADR-0073 §2).
 *
 * Nothing is booked: no money arrived.
 */
final readonly class FailInvoicePaymentUseCase
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoicePaymentRepository $payments,
        private InvoiceRepository $invoices,
        private CustomerRepository $customers,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function fail(string $paymentId, string $reason): ?InvoicePaymentView
    {
        return $this->unitOfWork->transactional(function () use ($paymentId, $reason): ?InvoicePaymentView {
            $now = $this->clock->now();

            $attempt = $this->payments->findByPaymentId($paymentId);

            // Not one of ours, or already moved: a replay does nothing.
            if ($attempt === null || ! $attempt->isPending()) {
                return null;
            }

            if (! $this->payments->markFailed($attempt->id, $reason, $now)) {
                return null;
            }

            $invoice = $this->invoices->findById($attempt->invoiceId);

            if ($invoice !== null) {
                $customer = $this->customers->findById($invoice->customerId);
                $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

                $this->outbox->append(new DomainEvent(
                    $this->identifier->generate(),
                    $invoice->projectId,
                    'invoice.payment_failed',
                    InvoiceEvents::payload($invoice, $customerRef, $reason),
                    null,
                    $now,
                ));
            }

            $failed = $this->payments->findById($attempt->id);

            return $failed === null ? null : InvoicePaymentViews::from($failed);
        });
    }
}
