<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Application\UseCase\AdvanceSubscriptionsUseCase;
use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ChangeSubscriptionPlanCommand;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPayment;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPaymentCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GetSubscriptionCommand;
use Core\Billing\Contracts\PauseSubscription;
use Core\Billing\Contracts\PauseSubscriptionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\ResumeSubscription;
use Core\Billing\Contracts\ResumeSubscriptionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;

/**
 * ADR-0093 — the corrections the review of Lot 10 brought, pinned by
 * tests.
 *
 * Each of them was a real defect: a scheduler that stopped halfway, a
 * resume that the database refused, a payment declaration that gave a
 * service away, a plan change that could not happen, an erased customer
 * that came back to life, a retired version that could be published
 * again.
 */
final class BillingReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '22222222-2222-4222-8222-222222222222';

    private DateTimeImmutable $now;

    #[Test]
    public function the_scheduler_prepares_the_next_period_and_rolls_over(): void
    {
        $subscription = $this->activeSubscription('acme', 'mensuel');

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscription->id);
        self::assertNotNull($current);

        // Seven days before the deadline (the default lead time), the
        // next period is prepared (ADR-0072 §4).
        $this->windTo($current->endsAt->modify('-7 days'));

        $report = app(AdvanceSubscriptionsUseCase::class)->advance();
        self::assertSame(1, $report->renewalsPrepared);
        self::assertSame(0, $report->failed);

        $upcoming = $periods->findBySeq($subscription->id, $current->seq + 1);
        self::assertNotNull($upcoming);
        self::assertEquals($current->endsAt, $upcoming->startsAt);

        // At the deadline, the next period becomes the running one.
        $this->windTo($current->endsAt->modify('+1 second'));

        $report = app(AdvanceSubscriptionsUseCase::class)->advance();
        self::assertSame(1, $report->renewed + $report->pastDue + $report->expired);

        $running = $periods->findCurrent($subscription->id);
        self::assertNotNull($running);
        self::assertSame($upcoming->id, $running->id);
    }

    #[Test]
    public function a_contract_that_cannot_be_advanced_does_not_stop_the_pass(): void
    {
        $blocked = $this->activeSubscription('bloquee', 'mensuel');
        $healthy = $this->activeSubscription('saine', 'mensuel');

        $periods = app(SubscriptionPeriodRepository::class);
        $blockedCurrent = $periods->findCurrent($blocked->id);
        $healthyCurrent = $periods->findCurrent($healthy->id);
        self::assertNotNull($blockedCurrent);
        self::assertNotNull($healthyCurrent);

        // A legacy row that overlaps the slot the renewal would take:
        // the renewal of that contract cannot be written.
        DB::table('subscription_periods')->insert([
            'id' => (string) Str::uuid(),
            'subscription_id' => $blocked->id,
            'project_id' => self::PROJECT,
            'seq' => 99,
            'kind' => 'paid',
            'starts_at' => $blockedCurrent->endsAt,
            'ends_at' => $blockedCurrent->endsAt->modify('+1 day'),
            'state' => 'upcoming',
            'invoice_id' => null,
            'reason' => null,
            'paid_at' => null,
            'created_at' => $this->now,
        ]);

        $this->windTo($blockedCurrent->endsAt->modify('-7 days'));

        $report = app(AdvanceSubscriptionsUseCase::class)->advance();

        // The blocked contract is counted, the other one is advanced.
        self::assertSame(1, $report->failed);
        self::assertSame(1, $report->renewalsPrepared);

        $healthyUpcoming = $periods->findBySeq($healthy->id, $healthyCurrent->seq + 1);
        self::assertNotNull($healthyUpcoming);
    }

    #[Test]
    public function a_resume_after_a_prepared_renewal_succeeds(): void
    {
        $subscription = $this->activeSubscription('acme', 'mensuel');

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscription->id);
        self::assertNotNull($current);

        // The renewal is prepared, then the operator suspends the
        // contract and takes it back: the current period is prolonged,
        // and the next one moves with it (ADR-0072 §2).
        $this->windTo($current->endsAt->modify('-7 days'));
        app(AdvanceSubscriptionsUseCase::class)->advance();

        $this->windTo($current->endsAt->modify('-3 days'));
        app(PauseSubscription::class)->pause(new PauseSubscriptionCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            reason: 'Pause de test',
            actor: 'test:billing',
            correlationId: null,
        ));

        $this->windTo($current->endsAt->modify('-1 day'));
        $resumed = app(ResumeSubscription::class)->resume(new ResumeSubscriptionCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('active', $resumed->status);

        // The two periods no longer overlap, and the customer kept the
        // days of the pause.
        $renewed = $periods->findCurrent($subscription->id);
        self::assertNotNull($renewed);
        self::assertGreaterThan($current->endsAt, $renewed->endsAt);
    }

    #[Test]
    public function only_the_running_billed_period_can_be_declared_settled(): void
    {
        $subscription = $this->activeSubscription('acme', 'mensuel');

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscription->id);
        self::assertNotNull($current);

        // A second period is prepared, then the operator declares the
        // settlement of the **first** one: the contract must not wake
        // up while its running period is unpaid.
        $this->windTo($current->endsAt->modify('-7 days'));
        app(AdvanceSubscriptionsUseCase::class)->advance();

        $this->expectException(InvalidSubscriptionTransition::class);

        app(ConfirmSubscriptionPeriodPayment::class)->confirm(new ConfirmSubscriptionPeriodPaymentCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            seq: $current->seq,
            reference: 'virement-2026-11-01',
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    #[Test]
    public function an_immediate_plan_change_succeeds_after_a_prepared_renewal(): void
    {
        $subscription = $this->activeSubscription('acme', 'mensuel');
        $this->catalogue('annuel', 9000);

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscription->id);
        self::assertNotNull($current);

        // The renewal is prepared (the rank of the next period is
        // taken), then the operator changes the plan at once.
        $this->windTo($current->endsAt->modify('-7 days'));
        app(AdvanceSubscriptionsUseCase::class)->advance();

        $this->windTo($current->endsAt->modify('-3 days'));

        $changed = app(ChangeSubscriptionPlan::class)->changePlan(new ChangeSubscriptionPlanCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            planCode: 'annuel',
            effective: 'immediate',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('active', $changed->status);

        $running = $periods->findCurrent($subscription->id);
        self::assertNotNull($running);
        self::assertSame($current->seq + 1, $running->seq);
    }

    #[Test]
    public function an_erased_customer_refuses_a_new_declaration(): void
    {
        $this->customer('acme');

        app(EraseCustomer::class)->erase(new EraseCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            actor: 'test:billing',
            correlationId: null,
        ));

        $this->expectException(InvalidCustomerTransition::class);

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME de nouveau',
            email: 'contact@acme.test',
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    #[Test]
    public function a_retired_plan_version_cannot_be_published_again(): void
    {
        $this->catalogue('mensuel', 5000);

        $planId = DB::table('plans')->where('code', 'mensuel')->value('id');
        self::assertNotNull($planId);

        // The version is retired by the database itself, then the
        // trigger refuses to let it go back to `published`.
        DB::table('plan_versions')
            ->where('plan_id', $planId)
            ->update(['status' => 'retired']);

        $this->expectException(QueryException::class);

        DB::table('plan_versions')
            ->where('plan_id', $planId)
            ->update(['status' => 'published']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-11-01T00:00:00Z');

        // ADR-0087 — the time is injected: the test drives the renewal
        // cycle without waiting for it.
        $clock = new class($this->now) implements Clock
        {
            public function __construct(public DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };

        $this->app->bind(Clock::class, static fn (): Clock => $clock);
    }

    /**
     * A customer, a plan, a published version and a **live** contract:
     * the first period is paid and settled, so the contract runs.
     */
    private function activeSubscription(string $customerRef, string $planCode): SubscriptionView
    {
        $this->customer($customerRef);
        $this->catalogue($planCode, 5000);

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: $customerRef,
            planCode: $planCode,
            trialDays: null,
            startAt: null,
            idempotencyKey: 'review-'.Str::uuid(),
            actor: 'test:billing',
            correlationId: null,
        ));

        $periods = app(SubscriptionPeriodRepository::class);
        $current = $periods->findCurrent($subscription->id);
        self::assertNotNull($current);

        // The first period is declared settled: the contract runs.
        app(ConfirmSubscriptionPeriodPayment::class)->confirm(new ConfirmSubscriptionPeriodPaymentCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            seq: $current->seq,
            reference: 'virement-2026-11-01',
            actor: 'test:billing',
            correlationId: null,
        ));

        $view = app(GetSubscription::class)->get(
            new GetSubscriptionCommand(self::PROJECT, $subscription->id),
            AccessScope::forProject(self::PROJECT),
        );

        self::assertNotNull($view);
        self::assertSame('active', $view->status);

        return $view;
    }

    private function customer(string $externalRef): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: $externalRef,
            displayName: 'Client '.$externalRef,
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * A plan and a **published** version — the terms a contract freezes.
     *
     * The helper is idempotent: a plan that already exists keeps the
     * version it has published (a plan carries one published version at
     * a time — ADR-0071 §2).
     */
    private function catalogue(string $code, int $priceMinor): void
    {
        $existing = DB::table('plans')
            ->where('project_id', self::PROJECT)
            ->where('code', $code)
            ->exists();

        if ($existing) {
            return;
        }

        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: self::PROJECT,
            code: $code,
            name: 'Plan '.$code,
            description: null,
            visibility: 'private',
            sortOrder: 0,
            actor: 'test:billing',
            correlationId: null,
        ));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: self::PROJECT,
            planCode: $code,
            priceMinor: $priceMinor,
            currency: 'XAF',
            intervalUnit: 'month',
            intervalCount: 1,
            trialDays: 0,
            graceDays: 7,
            graceAccess: true,
            features: [],
            actor: 'test:billing',
            correlationId: null,
        ));

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: self::PROJECT,
            planCode: $code,
            version: $version->version,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    private function windTo(DateTimeImmutable $moment): void
    {
        $clock = $this->app->make(Clock::class);
        $this->now = $moment;

        $property = (new ReflectionObject($clock))->getProperty('moment');
        $property->setValue($clock, $moment);
    }
}
