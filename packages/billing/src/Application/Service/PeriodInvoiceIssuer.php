<?php

declare(strict_types=1);

namespace Core\Billing\Application\Service;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Support\Observability\TraceContext;

/**
 * ADR-0073 §1, ADR-0094 §6 — the invoice of a period of a contract.
 *
 * The invoice is created **draft** and issued in the transaction that
 * prepares the period — the two requirements are satisfied at once: the
 * observer sees an **open** invoice, and the number is attributed at the
 * issuance (ADR-0094 §6).
 *
 * The amount is the price **frozen on the contract** (ADR-0072 §6): a
 * later version of the plan never touches what a period already bills.
 *
 * The invoice is issued by `billing` itself — the money of a contract is
 * the business of the module that owns the receivable (ADR-0073
 * §Alternatives : « Laisser `payment` connaître les factures. Rejeté »).
 */
final readonly class PeriodInvoiceIssuer
{
    public function __construct(
        private CreateInvoice $createInvoice,
        private IssueInvoice $issueInvoice,
        private CustomerRepository $customers,
        private PlanVersionRepository $versions,
        private PlanRepository $plans,
    ) {}

    /**
     * The invoice of a period — created and issued, in the caller's
     * transaction.
     *
     * @return string the id of the invoice
     */
    public function issue(Subscription $subscription, SubscriptionPeriod $period): string
    {
        $customer = $this->customers->findById($subscription->customerId);

        $invoice = $this->createInvoice->create(new CreateInvoiceCommand(
            projectId: $subscription->projectId,
            customerRef: $customer instanceof Customer ? $customer->externalRef : '',
            lines: [new InvoiceLineInput(
                kind: 'subscription',
                description: $this->description($subscription, $period),
                quantity: 1,
                unitAmountMinor: $subscription->priceMinor,
                taxRateBps: 0,
            )],
            // ADR-0072 §4 — the invoice of a period falls due when the
            // period ends.
            dueAt: $period->endsAt->format('Y-m-d\TH:i:sP'),
            currency: $subscription->currency,
            actor: 'service:billing',
            correlationId: TraceContext::correlationId(),
            subscriptionId: $subscription->id,
            periodId: $period->id,
        ));

        $this->issueInvoice->issue(new IssueInvoiceCommand(
            projectId: $subscription->projectId,
            invoiceId: $invoice->id,
            actor: 'service:billing',
            correlationId: TraceContext::correlationId(),
        ));

        return $invoice->id;
    }

    /**
     * The line says what it bills: the plan, and the period it covers.
     */
    private function description(Subscription $subscription, SubscriptionPeriod $period): string
    {
        $version = $this->versions->findById($subscription->planVersionId);
        $plan = $version === null ? null : $this->plans->findById($version->planId);
        $code = $plan === null ? 'abonnement' : $plan->code;

        return sprintf(
            'Abonnement %s — période du %s au %s',
            $code,
            $period->startsAt->format('d/m/Y'),
            $period->endsAt->format('d/m/Y'),
        );
    }
}
