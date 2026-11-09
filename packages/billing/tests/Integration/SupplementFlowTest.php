<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Application\UseCase\AdvanceSubscriptionsUseCase;
use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ApproveManualReceiptCommand;
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MarkInvoiceUncollectibleCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\PurchaseSubscriptionSupplement;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\SubscriptionSupplementPurchaseView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MutableClock;
use Tests\TestCase;

/**
 * ADR-0117 — the supplement against a real PostgreSQL.
 *
 * The scenario the customer lives in: a plan of a hundred WhatsApp
 * messages, the quota exhausted, and a **pack of fifty more** bought
 * without renewing anything. The customer keeps his plan, his period and
 * his hundred consumed messages; what the pack adds is fifty units of
 * head-room on the very same counter.
 *
 * The rules pinned here:
 *
 *  - the purchase issues its own invoice and grants **nothing** until
 *    that invoice is paid;
 *  - the money arriving activates the purchase, and the limit the quota
 *    enforces and the limit the entitlements show are **the same number**;
 *  - an annulled invoice cancels the purchase it carried, and so does an
 *    invoice written off as a bad debt (ADR-0121);
 *  - a pack covers the period it was paid for, and not the next one
 *    (ADR-0121);
 *  - a meter the plan does not carry, or a meter without a limit, is
 *    refused.
 */
final class SupplementFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '44444444-4444-4444-8444-444444444444';

    private const METER = 'whatsapp_messages';

    private const OPERATOR = '77777777-7777-4777-8777-777777777777';

    /** The clock the test drives, when it freezes the time. */
    private ?MutableClock $clock = null;

    #[Test]
    public function a_purchase_issues_its_own_invoice_and_grants_nothing_yet(): void
    {
        $this->subscribe(limit: 100);

        $purchase = $this->purchase(quantity: 50);

        self::assertSame('pending', $purchase->supplement->status);
        self::assertSame('open', $purchase->invoice->status);
        self::assertNotNull($purchase->invoice->number);
        self::assertSame(250_000, $purchase->invoice->subtotalMinor);
        self::assertSame($purchase->invoice->id, $purchase->supplement->invoiceId);
        self::assertSame($purchase->invoice->id, DB::table('subscription_supplements')->value('invoice_id'));

        // The order alone grants nothing: the limit did not move.
        self::assertSame(100, $this->entitlement('messages')['limit']);
    }

    #[Test]
    public function a_pack_of_fifty_opens_fifty_more_units_on_an_exhausted_plan(): void
    {
        $this->subscribe(limit: 100);

        // The hundred messages of the plan are consumed.
        self::assertTrue($this->consume('hundred', quantity: 100)->isAllowed());
        self::assertSame(UsageDecision::QUOTA_EXCEEDED, $this->consume('hundred-and-one')->status);

        // The customer buys a pack of fifty, and pays for it.
        $purchase = $this->purchase(quantity: 50);
        $this->settle($purchase->invoice->id, $purchase->invoice->totalMinor);

        self::assertSame('active', DB::table('subscription_supplements')->value('status'));

        // One more message goes through…
        $allowed = $this->consume('one-more');

        self::assertSame(UsageDecision::ADMITTED, $allowed->status);
        self::assertSame(150, $allowed->limit);
        self::assertSame(101, $allowed->used);
        self::assertSame(49, $allowed->remaining());

        // …and fifty-one do not: the pack was fifty, not more.
        $refused = $this->consume('too-many', quantity: 51);

        self::assertSame(UsageDecision::QUOTA_EXCEEDED, $refused->status);
        self::assertSame(150, $refused->limit);
        self::assertSame(101, $refused->used);
    }

    #[Test]
    public function the_entitlements_and_the_quota_read_the_same_limit(): void
    {
        $this->subscribe(limit: 100);
        $this->consume('one', quantity: 10);

        $purchase = $this->purchase(quantity: 50);
        $this->settle($purchase->invoice->id, $purchase->invoice->totalMinor);

        $entitlement = $this->entitlement('messages');

        // One single source of truth (ADR-0117 §3): the number the client
        // is told and the number the quota is enforced against.
        self::assertSame(150, $entitlement['limit']);
        self::assertSame(10, $entitlement['used']);
        self::assertSame(140, $entitlement['remaining']);
        self::assertSame($entitlement['limit'], $this->consume('two')->limit);
    }

    #[Test]
    public function an_annulled_invoice_cancels_the_purchase_it_carried(): void
    {
        $this->subscribe(limit: 100);

        $purchase = $this->purchase(quantity: 50);

        app(VoidInvoice::class)->void(new VoidInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $purchase->invoice->id,
            reason: 'The customer changed their mind.',
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));

        self::assertSame('canceled', DB::table('subscription_supplements')->value('status'));
        self::assertSame(100, $this->entitlement('messages')['limit']);
    }

    /**
     * ADR-0121 — a pack covers the period in which its money arrived: it
     * raises the limit of the period in progress, and the next period
     * starts again at the limit of the plan.
     */
    #[Test]
    public function a_pack_covers_the_period_it_was_paid_for_and_not_the_next_one(): void
    {
        $this->freezeTheClock(new DateTimeImmutable('2026-11-01T00:00:00Z'));

        $this->subscribe(limit: 100);

        $purchase = $this->purchase(quantity: 50);
        $this->settle($purchase->invoice->id, $purchase->invoice->totalMinor);

        self::assertSame(150, $this->entitlement('messages')['limit']);

        $subscriptionId = DB::table('subscriptions')
            ->where('project_id', self::PROJECT)
            ->value('id');
        self::assertIsString($subscriptionId);

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscriptionId);
        self::assertNotNull($current);

        // The period ends: the next one begins, and the pack does not
        // follow it.
        $this->windTo($current->endsAt->modify('+1 second'));
        app(AdvanceSubscriptionsUseCase::class)->advance();

        self::assertSame(100, $this->entitlement('messages')['limit']);
    }

    /**
     * ADR-0121 — a bad debt is the other way for the money never to
     * arrive: the supplement it carried granted nothing, and it must not
     * stay `pending` for ever — the portal would keep the meter out of
     * its offers, and the customer could never reload it.
     */
    #[Test]
    public function a_bad_debt_invoice_cancels_the_purchase_it_carried(): void
    {
        $this->subscribe(limit: 100);

        $purchase = $this->purchase(quantity: 50);

        app(MarkInvoiceUncollectible::class)->markUncollectible(new MarkInvoiceUncollectibleCommand(
            projectId: self::PROJECT,
            invoiceId: $purchase->invoice->id,
            reason: 'The customer will not pay.',
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));

        self::assertSame('canceled', DB::table('subscription_supplements')->value('status'));
        self::assertSame(100, $this->entitlement('messages')['limit']);
    }

    #[Test]
    public function a_purchase_of_a_meter_the_plan_does_not_carry_is_refused(): void
    {
        $this->subscribe(limit: 100);

        $this->expectException(InvalidSubscriptionSupplement::class);

        $this->purchase(quantity: 50, meter: 'storage_mb');
    }

    #[Test]
    public function a_purchase_on_a_meter_without_a_limit_is_refused(): void
    {
        $this->subscribe(limit: null);

        $this->expectException(InvalidSubscriptionSupplement::class);

        $this->purchase(quantity: 50);
    }

    #[Test]
    public function the_purchase_is_written_once(): void
    {
        $this->subscribe(limit: 100);

        $this->purchase(quantity: 50);

        self::assertSame(1, DB::table('subscription_supplements')->count());
        self::assertSame(1, DB::table('event_outbox')->where('type', 'subscription.supplement_purchased')->count());
    }

    /**
     * ADR-0087 — the time is injected: the test drives the cycle of the
     * periods without waiting for it.
     */
    private function freezeTheClock(DateTimeImmutable $moment): void
    {
        $this->clock = new MutableClock($moment);
        $this->app->instance(Clock::class, $this->clock);
    }

    private function windTo(DateTimeImmutable $moment): void
    {
        if ($this->clock === null) {
            self::fail('The clock is not frozen: the test never called freezeTime().');
        }

        $this->clock->moment = $moment;
    }

    /**
     * @return array<string, int|null>
     */
    private function entitlement(string $key): array
    {
        $view = app(GetEntitlements::class)->get(
            new GetEntitlementsCommand(self::PROJECT, 'acme'),
            AccessScope::forProject(self::PROJECT),
        );

        self::assertNotNull($view);

        foreach ($view->entitlements as $entitlement) {
            if ($entitlement->key === $key) {
                return [
                    'limit' => $entitlement->limit,
                    'used' => $entitlement->used,
                    'remaining' => $entitlement->remaining,
                ];
            }
        }

        self::fail(sprintf('The plan carries no feature named "%s".', $key));
    }

    private function consume(string $key, int $quantity = 1): UsageDecision
    {
        return app(ConsumeUsage::class)->consume(new ConsumeUsageCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            meter: self::METER,
            quantity: $quantity,
            idempotencyKey: $key,
            source: 'api',
        ));
    }

    private function purchase(int $quantity, string $meter = self::METER): SubscriptionSupplementPurchaseView
    {
        $subscriptionId = DB::table('subscriptions')->where('project_id', self::PROJECT)->value('id');

        self::assertIsString($subscriptionId);

        return app(PurchaseSubscriptionSupplement::class)->purchase(new PurchaseSubscriptionSupplementCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscriptionId,
            meter: $meter,
            quantity: $quantity,
            unitPriceMinor: 5_000,
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));
    }

    /**
     * The money arrives: the invoice is settled by an offline receipt
     * (ADR-0073 §4), and the purchase becomes active in that very
     * transaction (ADR-0117 §4).
     */
    private function settle(string $invoiceId, int $amountMinor): void
    {
        $receipt = app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: self::PROJECT,
            invoiceId: $invoiceId,
            method: 'bank_transfer',
            reference: 'VIR-2026-0001',
            amountMinor: $amountMinor,
            currency: 'XAF',
            receivedAt: '2026-11-02T08:00:00Z',
            recordedBy: self::OPERATOR,
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));

        if ($receipt->status === 'pending') {
            app(ApproveManualReceipt::class)->approve(new ApproveManualReceiptCommand(
                projectId: self::PROJECT,
                receiptId: $receipt->id,
                approver: self::OPERATOR,
                approved: true,
                actor: 'operator:'.self::OPERATOR,
                correlationId: null,
            ));
        }
    }

    /**
     * A customer on a trial of the `pro` plan, which carries one boolean
     * feature and one metered feature.
     */
    private function subscribe(?int $limit): void
    {
        $messages = ['type' => 'limit', 'meter' => self::METER, 'reset' => 'period', 'overage' => 'block'];

        if ($limit !== null) {
            $messages['limit'] = $limit;
        }

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:supplement',
            correlationId: null,
        ));

        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: self::PROJECT,
            code: 'pro',
            name: 'Pro',
            description: null,
            visibility: 'public',
            sortOrder: 0,
            actor: 'test:supplement',
            correlationId: null,
        ));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            priceMinor: 5_000,
            currency: 'XAF',
            intervalUnit: 'month',
            intervalCount: 1,
            trialDays: 14,
            graceDays: 7,
            graceAccess: true,
            features: [
                'api_access' => ['type' => 'boolean', 'enabled' => true],
                'messages' => $messages,
            ],
            actor: 'test:supplement',
            correlationId: null,
        ));

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            version: $version->version,
            actor: 'test:supplement',
            correlationId: null,
        ));

        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            planCode: 'pro',
            trialDays: 14,
            startAt: null,
            idempotencyKey: 'sub-acme',
            actor: 'test:supplement',
            correlationId: null,
        ));
    }
}
