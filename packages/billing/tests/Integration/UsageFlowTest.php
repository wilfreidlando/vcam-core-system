<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

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
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0074 — the quota authority, against a real PostgreSQL.
 *
 * The rules pinned here are the ones a client could otherwise exploit:
 * going beyond the plan, double-counting a replay, or slipping through
 * with a contract that no longer grants access.
 */
final class UsageFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '22222222-2222-4222-8222-222222222222';

    private const METER = 'whatsapp_messages';

    #[Test]
    public function a_unit_beyond_the_limit_is_refused_and_leaves_no_trace(): void
    {
        $this->subscribe('acme', limit: 3);

        foreach ([1, 2, 3] as $n) {
            self::assertSame(UsageDecision::ADMITTED, $this->consume('acme', "k{$n}")->status);
        }

        $refused = $this->consume('acme', 'k4');

        self::assertSame(UsageDecision::QUOTA_EXCEEDED, $refused->status);
        self::assertFalse($refused->isAllowed());
        self::assertSame(3, $refused->used);
        self::assertSame(0, $refused->remaining());

        // Nothing of the refused call was written — not even its key.
        self::assertEquals(3, DB::table('usage_counters')->value('used'));
        self::assertSame(3, DB::table('usage_records')->count());
        self::assertSame(0, DB::table('usage_idempotency')->where('idempotency_key', 'k4')->count());
    }

    #[Test]
    public function fifty_distinct_calls_against_a_limit_of_one_admit_exactly_one(): void
    {
        $this->subscribe('acme', limit: 1);

        $admitted = 0;

        for ($i = 0; $i < 50; $i++) {
            if ($this->consume('acme', "call-{$i}")->isAllowed()) {
                $admitted++;
            }
        }

        self::assertSame(1, $admitted);
        self::assertEquals(1, DB::table('usage_counters')->value('used'));
    }

    #[Test]
    public function a_replayed_key_counts_once_and_answers_the_same_decision(): void
    {
        $this->subscribe('acme', limit: 5);

        $first = $this->consume('acme', 'same-key');

        for ($i = 0; $i < 9; $i++) {
            $replay = $this->consume('acme', 'same-key');
            self::assertSame(UsageDecision::REPLAYED, $replay->status);
            self::assertTrue($replay->isAllowed());
        }

        self::assertSame(UsageDecision::ADMITTED, $first->status);
        self::assertEquals(1, DB::table('usage_counters')->value('used'));
        self::assertSame(1, DB::table('usage_records')->count());
    }

    #[Test]
    public function a_refused_call_can_be_replayed_with_the_same_key_once_room_is_made(): void
    {
        $this->subscribe('acme', limit: 1);

        self::assertTrue($this->consume('acme', 'a')->isAllowed());
        self::assertFalse($this->consume('acme', 'b')->isAllowed());

        // Releasing the unit makes room; the refused key was never consumed.
        self::assertTrue($this->consume('acme', 'release-a', quantity: -1)->isAllowed());
        self::assertTrue($this->consume('acme', 'b')->isAllowed());
    }

    #[Test]
    public function a_gauge_cannot_be_released_below_zero(): void
    {
        $this->subscribe('acme', limit: 5);

        $decision = $this->consume('acme', 'release', quantity: -1);

        self::assertSame(UsageDecision::UNDERFLOW, $decision->status);
        self::assertSame(0, DB::table('usage_records')->count());
    }

    #[Test]
    public function record_mode_writes_the_fact_even_beyond_the_limit(): void
    {
        $this->subscribe('acme', limit: 1);

        self::assertTrue($this->consume('acme', 'a')->isAllowed());
        self::assertSame(UsageDecision::ADMITTED, $this->consume('acme', 'late', mode: 'record')->status);
        self::assertEquals(2, DB::table('usage_counters')->value('used'));
    }

    #[Test]
    public function crossing_the_threshold_and_the_limit_each_publish_exactly_one_event(): void
    {
        $this->subscribe('acme', limit: 4, thresholdBps: 5000);

        foreach ([1, 2, 3, 4] as $n) {
            $this->consume('acme', "k{$n}");
        }

        self::assertFalse($this->consume('acme', 'k5')->isAllowed());

        self::assertSame(1, DB::table('event_outbox')->where('type', 'usage.threshold_reached')->count());
        self::assertSame(1, DB::table('event_outbox')->where('type', 'usage.limit_reached')->count());

        $raw = DB::table('event_outbox')->where('type', 'usage.threshold_reached')->value('payload');
        $payload = json_decode(is_string($raw) ? $raw : '{}', true);

        self::assertIsArray($payload);

        self::assertSame('acme', $payload['customer_ref']);
        self::assertSame(self::METER, $payload['meter']);
        self::assertSame(2, $payload['used']);
        self::assertArrayNotHasKey('email', $payload);
    }

    #[Test]
    public function a_meter_the_plan_does_not_carry_is_not_included(): void
    {
        $this->subscribe('acme', limit: 3);

        $decision = app(ConsumeUsage::class)->consume(new ConsumeUsageCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            meter: 'storage_mb',
            quantity: 1,
            idempotencyKey: 'x',
            source: 'api',
        ));

        self::assertSame(UsageDecision::FEATURE_NOT_INCLUDED, $decision->status);
    }

    #[Test]
    public function a_suspended_customer_loses_its_rights(): void
    {
        $this->subscribe('acme', limit: 3);

        app(SuspendCustomer::class)->suspend(new SuspendCustomerCommand(self::PROJECT, 'acme', 'test:usage', null));

        self::assertSame(UsageDecision::ENTITLEMENT_INACTIVE, $this->consume('acme', 'k1')->status);
        self::assertFalse(app(GetEntitlements::class)->get(
            new GetEntitlementsCommand(self::PROJECT, 'acme'),
            AccessScope::forProject(self::PROJECT),
        )?->active);
    }

    #[Test]
    public function an_unknown_customer_is_reported_as_such(): void
    {
        $this->subscribe('acme', limit: 3);

        self::assertSame(UsageDecision::CUSTOMER_NOT_FOUND, $this->consume('ghost', 'k1')->status);
    }

    #[Test]
    public function a_project_that_never_signed_a_contract_is_recorded_but_never_blocked(): void
    {
        $this->declareCustomer('acme');

        foreach (['a', 'b', 'c'] as $key) {
            self::assertTrue($this->consume('acme', $key)->isAllowed());
        }

        self::assertSame(3, DB::table('usage_records')->count());
    }

    #[Test]
    public function a_project_can_demand_that_every_metered_call_names_its_customer(): void
    {
        $settings = app(BillingSettingsRepository::class);
        $settings->save(new BillingSettings(
            projectId: self::PROJECT,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
            requireCustomerRef: true,
        ));

        $decision = $this->consume(null, 'k1');

        self::assertSame(UsageDecision::CUSTOMER_REF_REQUIRED, $decision->status);
        self::assertTrue($settings->find(self::PROJECT)->requireCustomerRef);
    }

    #[Test]
    public function entitlements_tell_what_is_left(): void
    {
        $this->subscribe('acme', limit: 3);
        $this->consume('acme', 'k1');

        $view = app(GetEntitlements::class)->get(
            new GetEntitlementsCommand(self::PROJECT, 'acme'),
            AccessScope::forProject(self::PROJECT),
        );

        self::assertNotNull($view);
        self::assertTrue($view->active);
        self::assertSame('pro', $view->planCode);

        $byKey = [];
        foreach ($view->entitlements as $entitlement) {
            $byKey[$entitlement->key] = $entitlement;
        }

        self::assertTrue($byKey['api_access']->enabled);
        self::assertSame(3, $byKey['messages']->limit);
        self::assertSame(1, $byKey['messages']->used);
        self::assertSame(2, $byKey['messages']->remaining);
        self::assertNotNull($byKey['messages']->windowEnd);
    }

    #[Test]
    public function the_entitlements_of_another_projects_customer_are_not_found(): void
    {
        $this->subscribe('acme', limit: 3);

        self::assertNull(app(GetEntitlements::class)->get(
            new GetEntitlementsCommand(self::PROJECT, 'acme'),
            AccessScope::forProject('99999999-9999-4999-8999-999999999999'),
        ));
    }

    #[Test]
    public function a_release_is_never_refused_for_being_over_the_limit(): void
    {
        $this->subscribe('acme', limit: 1);

        self::assertTrue($this->consume('acme', 'a')->isAllowed());
        self::assertTrue($this->consume('acme', 'late', mode: 'record')->isAllowed()); // used = 2, limit = 1

        // Giving a unit back must work even though the counter is above its limit.
        self::assertTrue($this->consume('acme', 'refund', quantity: -1)->isAllowed());
        self::assertEquals(1, DB::table('usage_counters')->value('used'));
    }

    #[Test]
    public function a_key_reused_for_another_call_is_a_conflict_not_a_replay(): void
    {
        $this->subscribe('acme', limit: 5);

        self::assertSame(UsageDecision::ADMITTED, $this->consume('acme', 'k')->status);
        self::assertSame(UsageDecision::REPLAYED, $this->consume('acme', 'k')->status);

        // Same key, different quantity: refused, nothing counted.
        self::assertSame(UsageDecision::KEY_CONFLICT, $this->consume('acme', 'k', quantity: 3)->status);
        self::assertEquals(1, DB::table('usage_counters')->where('customer_id', DB::table('customers')->where('external_ref', 'acme')->value('id'))->value('used'));
    }

    #[Test]
    public function a_gauge_follows_the_plan_it_is_now_on_not_the_one_it_was_created_under(): void
    {
        $this->subscribe('acme', limit: 5, reset: 'never');

        self::assertTrue($this->consume('acme', 'a')->isAllowed());

        // The counter was opened under a smaller plan (simulated): the plan now says 5.
        DB::table('usage_counters')->update(['limit_value' => 1]);

        self::assertTrue($this->consume('acme', 'b')->isAllowed());
        self::assertEquals(5, DB::table('usage_counters')->value('limit_value'));
    }

    #[Test]
    public function a_zero_quantity_is_refused_before_anything_is_written(): void
    {
        $this->subscribe('acme', limit: 3);

        try {
            $this->consume('acme', 'k1', quantity: 0);
            self::fail('A zero quantity must be refused.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('usage_records')->count());
        }
    }

    private function consume(?string $customerRef, string $key, int $quantity = 1, string $mode = 'enforce'): UsageDecision
    {
        return app(ConsumeUsage::class)->consume(new ConsumeUsageCommand(
            projectId: self::PROJECT,
            customerRef: $customerRef,
            meter: self::METER,
            quantity: $quantity,
            idempotencyKey: $key,
            source: 'api',
            mode: $mode,
        ));
    }

    private function declareCustomer(string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: $ref,
            displayName: 'Client',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:usage',
            correlationId: null,
        ));
    }

    /**
     * A customer on a trial of the `pro` plan, which carries one boolean
     * feature and one metered feature.
     */
    private function subscribe(string $ref, int $limit, ?int $thresholdBps = null, string $reset = 'period'): void
    {
        $messages = ['type' => 'limit', 'meter' => self::METER, 'limit' => $limit, 'reset' => $reset, 'overage' => 'block'];

        if ($thresholdBps !== null) {
            $messages['threshold_bps'] = $thresholdBps;
        }

        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: self::PROJECT,
            code: 'pro',
            name: 'Pro',
            description: null,
            visibility: 'public',
            sortOrder: 0,
            actor: 'test:usage',
            correlationId: null,
        ));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            priceMinor: 5_000,
            currency: 'XAF',
            intervalUnit: 'month',
            intervalCount: 1,
            trialDays: 0,
            graceDays: 7,
            graceAccess: true,
            features: ['api_access' => ['type' => 'boolean', 'enabled' => true], 'messages' => $messages],
            actor: 'test:usage',
            correlationId: null,
        ));

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            version: $version->version,
            actor: 'test:usage',
            correlationId: null,
        ));

        $this->declareCustomer($ref);

        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: $ref,
            planCode: 'pro',
            trialDays: 14,
            startAt: null,
            idempotencyKey: 'sub-'.$ref,
            actor: 'test:usage',
            correlationId: null,
        ));
    }
}
