<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

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
use Core\Billing\Contracts\GrantComplimentaryPeriod;
use Core\Billing\Contracts\GrantComplimentaryPeriodCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0070 / ADR-0071 / ADR-0072 — the commerce of a project, against a
 * real PostgreSQL.
 *
 * The rules these tests pin are the ones the code could otherwise break
 * quietly:
 *
 *  - the customer's contact is **encrypted at rest** and the uniqueness of
 *    an address is **per project** (ADR-0070 §1, ADR-0083);
 *  - a published plan version is **frozen** — in the database, by the
 *    trigger (ADR-0071 §2);
 *  - the payment declaration of Lot 10 **moves no money** — it writes the
 *    fact, and the invoice arrives with Lot 11 (ADR-0092 §2).
 */
final class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function a_customer_is_declared_once_and_its_contact_is_encrypted_at_rest(): void
    {
        $customers = app(UpsertCustomer::class);

        $created = $customers->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'Contact@Acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: ['segment' => 'b2b'],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('acme', $created->externalRef);
        self::assertSame(1, $created->version);

        // The clear address never reaches the disk.
        $row = DB::table('customers')->where('external_ref', 'acme')->first();

        self::assertNotNull($row);
        self::assertIsString($row->email_ciphertext);
        self::assertIsString($row->email_bidx);
        self::assertNotSame('Contact@Acme.test', $row->email_ciphertext);
        self::assertStringStartsWith('pii:v1:', $row->email_ciphertext);
        self::assertNotSame('contact@acme.test', $row->email_bidx);
        self::assertSame(64, strlen($row->email_bidx));

        // The upsert is idempotent by reference: the same call updates.
        $updated = $customers->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME SA',
            email: 'Contact@Acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame($created->id, $updated->id);
        self::assertSame('ACME SA', $updated->displayName);
        self::assertSame(2, $updated->version);
        self::assertSame(1, DB::table('customers')->count());
    }

    #[Test]
    public function an_address_is_free_in_another_project(): void
    {
        $customers = app(UpsertCustomer::class);

        $customers->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'contact@acme.test',
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        // The uniqueness of an address is per project (ADR-0070 §Sécurité):
        // another project's customer may carry the same address.
        $other = $customers->upsert(new UpsertCustomerCommand(
            projectId: '22222222-2222-4222-8222-222222222222',
            externalRef: 'acme',
            displayName: 'ACME (autre projet)',
            email: 'contact@acme.test',
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('acme', $other->externalRef);
        self::assertSame(2, DB::table('customers')->count());
    }

    #[Test]
    public function erasing_a_customer_destroys_its_identity_and_keeps_its_row(): void
    {
        $customers = app(UpsertCustomer::class);

        $created = $customers->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'contact@acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: ['segment' => 'b2b'],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        $erased = app(EraseCustomer::class)->erase(new EraseCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('erased', $erased->status);
        self::assertNull($erased->email);
        self::assertNull($erased->phone);
        self::assertSame('', $erased->displayName);

        // The row stays: the invoices reference it (ADR-0083).
        $row = DB::table('customers')->where('id', $created->id)->first();

        self::assertNotNull($row);
        self::assertNull($row->email_ciphertext);
        self::assertNull($row->phone_ciphertext);
        self::assertNotNull($row->erased_at);
    }

    #[Test]
    public function a_suspended_customer_loses_its_rights(): void
    {
        $customers = app(UpsertCustomer::class);

        $customers->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        $suspended = app(SuspendCustomer::class)->suspend(new SuspendCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('suspended', $suspended->status);
    }

    #[Test]
    public function a_published_plan_version_is_frozen_by_the_database(): void
    {
        $this->publishPlan('pro', 5_000);

        // ADR-0071 §2 — a published version's terms are frozen, in the
        // database: the trigger refuses the change.
        $this->expectException(QueryException::class);

        DB::table('plan_versions')
            ->where('plan_id', DB::table('plans')->where('code', 'pro')->value('id'))
            ->update(['price_minor' => 9_999]);
    }

    #[Test]
    public function a_trial_subscription_becomes_active_through_a_complimentary_period(): void
    {
        $this->publishPlan('pro', 5_000);
        $this->declareCustomer('acme');

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            planCode: 'pro',
            trialDays: 14,
            startAt: null,
            idempotencyKey: 'key-1',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('trialing', $subscription->status);
        self::assertNotNull($subscription->trialEndsAt);
        self::assertTrue($subscription->hasAccess);

        // The trial period exists, and it is free.
        $periods = DB::table('subscription_periods')->where('subscription_id', $subscription->id)->get();

        self::assertCount(1, $periods);
        $firstPeriod = $periods->first();
        self::assertNotNull($firstPeriod);
        self::assertSame('trial', $firstPeriod->kind);

        // ADR-0092 §1 — the complimentary period is the public path to
        // `active` in this lot: no invoice, no money.
        $active = app(GrantComplimentaryPeriod::class)->grant(new GrantComplimentaryPeriodCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            days: 30,
            reason: 'Offre de lancement',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('active', $active->status);
    }

    #[Test]
    public function the_payment_declaration_writes_the_fact_and_moves_no_money(): void
    {
        $this->publishPlan('pro', 5_000);
        $this->declareCustomer('acme');

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            planCode: 'pro',
            trialDays: null,
            startAt: null,
            idempotencyKey: 'key-2',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('incomplete', $subscription->status);

        // The paid period has **no invoice** yet (ADR-0092 §1).
        $period = DB::table('subscription_periods')->where('subscription_id', $subscription->id)->first();

        self::assertNotNull($period);
        self::assertIsInt($period->seq);
        self::assertSame('paid', $period->kind);
        self::assertNull($period->invoice_id);

        $entriesBefore = DB::table('ledger_entries')->count();

        $confirmed = app(ConfirmSubscriptionPeriodPayment::class)->confirm(new ConfirmSubscriptionPeriodPaymentCommand(
            projectId: self::PROJECT,
            subscriptionId: $subscription->id,
            seq: $period->seq,
            reference: 'virement-2026-10-27',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('active', $confirmed->status);

        $settled = DB::table('subscription_periods')->where('id', $period->id)->first();

        self::assertNotNull($settled);
        self::assertNotNull($settled->paid_at);

        // ADR-0092 §2 — the declaration moves no money: the ledger is
        // untouched.
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
    }

    #[Test]
    public function the_contract_of_another_project_is_not_found(): void
    {
        $this->publishPlan('pro', 5_000);
        $this->declareCustomer('acme');

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            planCode: 'pro',
            trialDays: 7,
            startAt: null,
            idempotencyKey: 'key-3',
            actor: 'test:billing',
            correlationId: null,
        ));

        // ADR-0069 §2 / S-04 — a contract of another project is not
        // found, never "forbidden".
        $found = app(GetSubscription::class)->get(
            new GetSubscriptionCommand('99999999-9999-4999-8999-999999999999', $subscription->id),
            AccessScope::forProject('99999999-9999-4999-8999-999999999999'),
        );

        self::assertNull($found);
    }

    private function declareCustomer(string $externalRef): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: $externalRef,
            displayName: 'Client',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    private function publishPlan(string $code, int $priceMinor): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: self::PROJECT,
            code: $code,
            name: 'Plan '.$code,
            description: null,
            visibility: 'public',
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
            features: ['api_access' => ['type' => 'boolean', 'enabled' => true]],
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
}
