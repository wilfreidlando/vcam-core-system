<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoiceEvents;
use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Exception\InvalidInvoiceTransition;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoiceLine;
use Core\Billing\Domain\Model\InvoiceLineKind;
use Core\Billing\Domain\Model\InvoiceStatus;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Privacy\PersonalDataCipher;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0073 §1 — `POST /v1/invoices`: a **manual** invoice, created
 * `draft`.
 *
 * A draft has no number: the number is attributed at the issuance
 * (ADR-0094 §5). Creating and issuing are two acts because they are two
 * decisions — a draft can be corrected, an issued invoice cannot.
 *
 * The customer is frozen on the invoice as it is **now**: the personal
 * values are encrypted (ADR-0083 §2, ADR-0094 §2), and the reference is
 * the project's own opaque key.
 */
final readonly class CreateInvoiceUseCase implements CreateInvoice
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
        private BillingSettingsRepository $settings,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private PersonalDataCipher $cipher,
    ) {}

    public function create(CreateInvoiceCommand $command): InvoiceView
    {
        return $this->unitOfWork->transactional(function () use ($command): InvoiceView {
            $now = $this->clock->now();

            $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

            if ($customer === null) {
                throw CustomerNotFound::becauseExternalRef($command->customerRef);
            }

            // ADR-0093 §4 — an erased customer is final: it cannot be
            // written to again, not even to bill it.
            if ($customer->isErased()) {
                throw InvalidCustomerTransition::becauseNotAllowed($customer->status->value, 'a new declaration');
            }

            if ($command->lines === []) {
                throw InvalidInvoiceTransition::becauseNoLine();
            }

            $settings = $this->settings->find($command->projectId);

            $currency = $command->currency ?? $settings->defaultCurrency;
            $defaultTaxRateBps = $command->taxRateBps ?? $settings->defaultTaxRateBps;

            $invoiceId = $this->identifier->generate();

            $subtotalMinor = 0;
            $taxMinor = 0;
            $invoiceLines = [];

            foreach ($command->lines as $input) {
                $kind = $input->kind === null
                    ? InvoiceLineKind::Manual
                    : InvoiceLineKind::tryFrom($input->kind)
                        ?? throw InvalidInvoiceTransition::becauseUnknownLineKind($input->kind);

                $line = InvoiceLine::of(
                    id: $this->identifier->generate(),
                    invoiceId: $invoiceId,
                    projectId: $command->projectId,
                    kind: $kind,
                    description: $input->description,
                    quantity: $input->quantity,
                    unitAmountMinor: $input->unitAmountMinor,
                    taxRateBps: $input->taxRateBps ?? $defaultTaxRateBps,
                    createdAt: $now,
                );

                $subtotalMinor += $line->amountMinor;
                $taxMinor += $line->taxAmountMinor;

                $invoiceLines[] = $line;
            }

            $invoice = new Invoice(
                id: $invoiceId,
                projectId: $command->projectId,
                customerId: $customer->id,
                subscriptionId: $command->subscriptionId,
                periodId: $command->periodId,
                number: null,
                status: InvoiceStatus::Draft,
                currency: $currency,
                subtotalMinor: $subtotalMinor,
                taxMinor: $taxMinor,
                totalMinor: $subtotalMinor + $taxMinor,
                amountPaidMinor: 0,
                customerSnapshot: $this->snapshot($customer),
                dueAt: $this->dueAt($command->dueAt),
                issuedAt: null,
                paidAt: null,
                voidedAt: null,
                voidReason: null,
                ledgerTransactionId: null,
                version: 1,
                createdAt: $now,
                updatedAt: $now,
            );

            $this->invoices->save($invoice);

            foreach ($invoiceLines as $line) {
                $this->lines->save($line);
            }

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'invoice.created',
                InvoiceEvents::payload($invoice, $customer->externalRef),
                $command->correlationId,
                $now,
            ));

            return InvoiceViews::from($invoice, $customer->externalRef, $invoiceLines);
        });
    }

    /**
     * ADR-0083 §2 / ADR-0094 §2 — the customer frozen on the invoice:
     * the personal values are **ciphertexts**, the reference is the
     * project's own opaque key.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Customer $customer): array
    {
        return [
            'external_ref' => $customer->externalRef,
            'display_name_ciphertext' => $customer->displayName === ''
                ? null
                : $this->cipher->encrypt($customer->displayName),
            'email_ciphertext' => $customer->email === null
                ? null
                : $this->cipher->encrypt($customer->email),
            'phone_ciphertext' => $customer->phone === null
                ? null
                : $this->cipher->encrypt($customer->phone),
            'locale' => $customer->locale,
        ];
    }

    /**
     * The due date is optional: an invoice without one is payable as soon
     * as it is received.
     */
    private function dueAt(?string $dueAt): ?DateTimeImmutable
    {
        if ($dueAt === null || trim($dueAt) === '') {
            return null;
        }

        return new DateTimeImmutable($dueAt);
    }
}
