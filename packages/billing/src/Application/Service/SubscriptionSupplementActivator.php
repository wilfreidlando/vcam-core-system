<?php

declare(strict_types=1);

namespace Core\Billing\Application\Service;

use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\SubscriptionSupplement;
use Core\Billing\Domain\Model\SubscriptionSupplementStatus;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use DateTimeImmutable;

/**
 * ADR-0117 §4 — the effect of a settled purchase.
 *
 * The allowance is granted **when the money arrives**, never when the
 * order is placed: the supplement is `pending` while its invoice is open
 * and becomes `active` in the very transaction that settles that
 * invoice — online (ADR-0073 §2) or offline (ADR-0073 §4).
 *
 * The reverse is true too: an invoice that is annulled before any money
 * arrived cancels the supplements it carries, and they never granted
 * anything.
 *
 * The event is published **in the transaction** that moves the state
 * (G8/G8c).
 */
final readonly class SubscriptionSupplementActivator
{
    public function __construct(
        private SubscriptionSupplementRepository $supplements,
        private SubscriptionRepository $subscriptions,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    /**
     * ADR-0117 §4 — the invoice is settled: its supplements grant their
     * allowance from this instant.
     */
    public function settle(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        $now = $this->clock->now();

        foreach ($this->supplements->listByInvoice($invoice->id) as $supplement) {
            if ($supplement->status !== SubscriptionSupplementStatus::Pending) {
                continue;
            }

            $supplement->activate($now, $this->periodOf($supplement));
            $this->supplements->save($supplement);

            $this->publish('subscription.supplement_activated', $supplement, $invoice->projectId, $now);
        }
    }

    /**
     * ADR-0117 §4 — the invoice was annulled: the supplements it carried
     * never granted anything.
     */
    public function cancel(Invoice $invoice): void
    {
        $now = $this->clock->now();

        foreach ($this->supplements->listByInvoice($invoice->id) as $supplement) {
            if ($supplement->status !== SubscriptionSupplementStatus::Pending) {
                continue;
            }

            $supplement->cancel($now);
            $this->supplements->save($supplement);

            $this->publish('subscription.supplement_canceled', $supplement, $invoice->projectId, $now);
        }
    }

    /**
     * ADR-0121 — the period the contract is in when the money arrives.
     *
     * A payment that lands after the end of the period the order was
     * placed in covers the period running then: the allowance is never
     * attached to nothing, and the customer is never left having paid
     * for a quota they cannot spend.
     */
    private function periodOf(SubscriptionSupplement $supplement): ?string
    {
        $subscription = $this->subscriptions->findById($supplement->subscriptionId);

        return $subscription?->currentPeriodId;
    }

    /**
     * No personal data (ADR-0070 §3): the contract is named by its id,
     * and the customer by the reference the project knows it by — which
     * the consumer reads through the API.
     */
    private function publish(
        string $type,
        SubscriptionSupplement $supplement,
        string $projectId,
        DateTimeImmutable $now,
    ): void {
        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $projectId,
            $type,
            [
                'supplement_id' => $supplement->id,
                'subscription_id' => $supplement->subscriptionId,
                'meter' => $supplement->meter,
                'quantity' => $supplement->quantity,
                'status' => $supplement->status->value,
                'invoice_id' => $supplement->invoiceId,
            ],
            null,
            $now,
        ));
    }
}
