<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\SupplementViews;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\PurchaseSubscriptionSupplement;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementCommand;
use Core\Billing\Contracts\SubscriptionSupplementPurchaseView;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\FeatureType;
use Core\Billing\Domain\Model\InvoiceLineKind;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Model\SubscriptionSupplement;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0117 §4 — the purchase of a supplement: units bought **on top of**
 * the limit of a metered feature.
 *
 * The gesture is deliberately **not** a subscription and **not** a
 * renewal (ADR-0074 §3): the customer keeps his plan, his period and
 * his counter. What the purchase does is add a quantity to the limit of
 * one meter — and carry **its own invoice**, so the money that buys it
 * is traced like every other sum (ADR-0073, G5).
 *
 * Three things happen in one transaction:
 *
 *  - the **supplement** is created, `pending` — the terms are frozen
 *    (ADR-0071 §Conséquences);
 *  - the **invoice** is created and issued: the number is attributed and
 *    the receivable is booked in the ledger (ADR-0094 §6);
 *  - the event says so (G8/G8c).
 *
 * The allowance itself is **not** granted here: it is granted when that
 * invoice is paid (ADR-0117 §2), in the transaction that settles it.
 */
final readonly class PurchaseSubscriptionSupplementUseCase implements PurchaseSubscriptionSupplement
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private SubscriptionRepository $subscriptions,
        private PlanVersionRepository $versions,
        private CustomerRepository $customers,
        private SubscriptionSupplementRepository $supplements,
        private CreateInvoice $createInvoice,
        private IssueInvoice $issueInvoice,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function purchase(PurchaseSubscriptionSupplementCommand $command): SubscriptionSupplementPurchaseView
    {
        return $this->unitOfWork->transactional(function () use ($command): SubscriptionSupplementPurchaseView {
            $now = $this->clock->now();

            $subscription = $this->subscriptions->findById($command->subscriptionId);

            // A contract of another project is **not found**, never told
            // apart from one that does not exist (ADR-0081 S-04).
            if ($subscription === null || $subscription->projectId !== $command->projectId) {
                throw SubscriptionNotFound::becauseId($command->subscriptionId);
            }

            $version = $this->versions->findById($subscription->planVersionId);

            if ($version === null) {
                throw InvalidSubscriptionSupplement::becausePlanVersionMissing();
            }

            // ADR-0072 §3 — a contract that grants no right has no limit
            // to add to.
            if (! $subscription->hasAccess($version->graceAccess)) {
                throw InvalidSubscriptionSupplement::becauseSubscriptionHasNoAccess();
            }

            [$featureKey] = $this->meteredFeatureOf($version, $command->meter);

            $customer = $this->customers->findById($subscription->customerId);

            if ($customer === null || ! $customer->hasAccess()) {
                throw CustomerNotFound::becauseExternalRef($subscription->customerId);
            }

            // Une facture de zéro n'existe pas : le grand livre refuse une
            // ligne à zéro. Un pack gratuit est donc refusé **avant**
            // toute écriture, avec une phrase claire.
            if ($command->unitPriceMinor <= 0 || $command->quantity <= 0) {
                throw InvalidSubscriptionSupplement::becausePackIsFree($command->meter);
            }

            $supplement = SubscriptionSupplement::purchase(
                id: $this->identifier->generate(),
                projectId: $command->projectId,
                subscriptionId: $subscription->id,
                customerId: $customer->id,
                meter: $command->meter,
                featureKey: $featureKey,
                quantity: $command->quantity,
                unitPriceMinor: $command->unitPriceMinor,
                currency: $subscription->currency,
                periodId: $subscription->currentPeriodId,
                createdAt: $now,
                // ADR-0119 §6 — the key of the gesture, when the caller
                // has one: a replay finds this very supplement instead of
                // buying a second time.
                idempotencyKey: $command->idempotencyKey === '' ? null : $command->idempotencyKey,
            );

            $this->supplements->save($supplement);

            // ADR-0117 §4 — the invoice that carries the purchase. It is
            // a normal invoice of the module: the number is attributed,
            // the receivable is booked, the tax is rounded on the line
            // (ADR-0073 §1, ADR-0094 §6).
            $invoice = $this->createInvoice->create(new CreateInvoiceCommand(
                projectId: $command->projectId,
                customerRef: $customer->externalRef,
                lines: [
                    new InvoiceLineInput(
                        description: $command->description ?? $this->descriptionOf($command),
                        quantity: $supplement->quantity,
                        unitAmountMinor: $supplement->unitPriceMinor,
                        taxRateBps: null,
                        // The line is a one-off service (ADR-0073 §1): the
                        // register of the natures of a line is not opened
                        // for a supplement (ADR-0117 §4).
                        kind: InvoiceLineKind::Manual->value,
                    ),
                ],
                dueAt: null,
                currency: $supplement->currency,
                taxRateBps: null,
                actor: $command->actor,
                correlationId: $command->correlationId,
                subscriptionId: $subscription->id,
                // The invoice does **not** bill a period: settling it
                // must never release a period of the contract
                // (ADR-0094 §8).
                periodId: null,
            ));

            $issued = $this->issueInvoice->issue(new IssueInvoiceCommand(
                projectId: $command->projectId,
                invoiceId: $invoice->id,
                actor: $command->actor,
                correlationId: $command->correlationId,
            ));

            $supplement->attachInvoice($issued->id, $now);
            $this->supplements->save($supplement);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'subscription.supplement_purchased',
                [
                    'supplement_id' => $supplement->id,
                    'subscription_id' => $subscription->id,
                    'customer_ref' => $customer->externalRef,
                    'meter' => $supplement->meter,
                    'quantity' => $supplement->quantity,
                    'total_minor' => $supplement->totalMinor(),
                    'currency' => $supplement->currency,
                    'invoice_id' => $issued->id,
                    'invoice_number' => $issued->number,
                    'status' => $supplement->status->value,
                ],
                $command->correlationId,
                $now,
            ));

            return new SubscriptionSupplementPurchaseView(
                supplement: SupplementViews::from($supplement, $customer->externalRef, $issued->number),
                invoice: $issued,
            );
        });
    }

    /**
     * ADR-0117 §2 — the metered feature of the plan the supplement
     * extends.
     *
     * The Core knows no meter name (ADR-0071 §3): it looks the meter up
     * in the features of the plan version the contract was signed on.
     * A meter that the plan does not carry, or that carries no limit, is
     * refused — there would be nothing to add to.
     *
     * @return array{0: string} the key of the feature, in the plan version
     */
    private function meteredFeatureOf(PlanVersion $version, string $meter): array
    {
        foreach ($version->features->all() as $key => $feature) {
            if ($feature->type !== FeatureType::Limit || $feature->meter !== $meter) {
                continue;
            }

            if ($feature->limit === null) {
                throw InvalidSubscriptionSupplement::becauseUnlimitedMeter($meter);
            }

            return [$key];
        }

        throw InvalidSubscriptionSupplement::becauseMeterNotInPlan($meter);
    }

    /**
     * The wording of the invoice line when the caller gives none.
     */
    private function descriptionOf(PurchaseSubscriptionSupplementCommand $command): string
    {
        return sprintf('Supplement — %d × %s', $command->quantity, $command->meter);
    }
}
