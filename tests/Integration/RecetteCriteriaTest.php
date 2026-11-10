<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Application\UseCase\AdvanceSubscriptionsUseCase;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\UpdateProject;
use Core\Identity\Contracts\UpdateProjectCommand;
use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;

/**
 * Plan de recette — spec fonctionnelle §F8. The criteria that no other test
 * proves on its own are proven here, end to end, on the injected test clock
 * and the payment simulator (ADR-0066, ADR-0087). The map of every criterion
 * to its proving test lives in `docs/specs/recette-matrice.md`.
 *
 * App-level on purpose (G3c): each scenario orchestrates several modules.
 */
final class RecetteCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://mon-saas.example.cm/webhooks/core';

    private const PRICE = 25_000;

    private object $clock;

    private string $token = '';

    private string $projectId = '';

    /** L'URL du webhook : unique par test, pour qu'un reliquat ne collide jamais. */
    private string $webhook = '';

    // ---------------------------------------------------------------- R-03

    #[Test]
    public function r03_the_trial_journey_runs_on_the_test_clock_and_replayed_events_change_nothing(): void
    {
        // F4.2 steps 1-2: the customer is declared, then subscribed with a 14-day trial.
        $this->declareCustomer();
        $subscription = $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro', 'trial_days' => 14], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonPath('status', 'trialing');
        $trialEnd = new DateTimeImmutable($this->str($subscription->json('trial_ends_at')));
        $this->assertRights(true);

        // Before the lead time: nothing is billed yet.
        $this->goTo($trialEnd->modify('-8 days'));
        $this->scheduler();
        self::assertSame(0, DB::table('invoices')->count());

        // F4.2 step 3: seven days before the end of the trial, the first
        // billed period is invoiced and the project is told. A second pass
        // (the scheduler runs every five minutes) changes nothing.
        $this->goTo($trialEnd->modify('-7 days'));
        $this->scheduler();
        $this->scheduler();
        self::assertSame(1, DB::table('invoices')->where('status', 'open')->count(), 'one open invoice for the first billed period');
        self::assertSame(1, $this->eventCount('subscription.renewal_due'), 'renewal_due is published once, however many passes run');
        self::assertSame('trialing', $this->contractStatus());

        // F4.2 step 4-5: the customer pays; the provider confirms the right amount.
        $paymentId = $this->payOpenInvoice();
        self::assertSame(1, DB::table('invoices')->where('status', 'paid')->count());
        self::assertSame('trialing', $this->contractStatus(), 'paying early does not end the trial early');

        // The provider repeats its callback: no second settlement, no second event.
        $transactions = DB::table('ledger_transactions')->count();
        $this->replayProviderSuccess($paymentId, self::PRICE);
        $this->relay();
        self::assertSame($transactions, DB::table('ledger_transactions')->count(), 'a replayed callback books nothing more');
        self::assertSame(1, $this->eventCount('invoice.paid'));
        self::assertSame(1, DB::table('invoices')->where('status', 'paid')->count());

        // The trial ends: the paid period takes over and the contract is active.
        $this->goTo($trialEnd->modify('+1 minute'));
        $this->scheduler();
        $this->scheduler();
        self::assertSame('active', $this->contractStatus());
        self::assertSame(1, $this->eventCount('subscription.activated'));
        $this->assertRights(true);

        // The webhooks reach the project in the logical order of the journey.
        $delivered = $this->deliveredTypes();
        $logical = ['subscription.created', 'subscription.renewal_due', 'invoice.paid', 'subscription.activated'];
        self::assertSame($logical, array_values(array_intersect($delivered, $logical)), 'webhooks arrive in the logical order');
        self::assertSame(1, count(array_keys($delivered, 'subscription.activated', true)), 'each webhook is delivered once');
    }

    // ---------------------------------------------------------------- R-04

    #[Test]
    public function r04_an_advance_payment_loses_no_period_and_overlaps_none(): void
    {
        $this->declareCustomer();
        $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
        $this->payOpenInvoice();
        self::assertSame('active', $this->contractStatus());

        $first = $this->periods()[0];

        // F4.3 step 1: the renewal invoice exists as soon as the next period is
        // prepared; the customer pays it at once, long before the deadline.
        $this->goTo($first['ends_at']->modify('-6 days'));
        $this->scheduler();
        self::assertCount(2, $this->periods(), 'the renewal prepared exactly one next period');
        $this->payOpenInvoice();
        self::assertSame(2, DB::table('invoices')->where('status', 'paid')->count());

        // Paying did not create a third period, nor move the running one.
        $periods = $this->periods();
        self::assertCount(2, $periods);
        self::assertEquals($first['ends_at'], $periods[0]['ends_at']);
        self::assertEquals($first['ends_at'], $periods[1]['starts_at'], 'the paid period starts exactly when the running one ends');

        // The deadline passes: the paid period takes over; nothing is late.
        $this->goTo($first['ends_at']->modify('+1 minute'));
        $this->scheduler();
        $this->scheduler();
        self::assertSame('active', $this->contractStatus());
        $this->assertRights(true);
        self::assertSame(1, $this->eventCount('subscription.renewed'));
        self::assertSame(0, $this->eventCount('subscription.past_due'));

        $periods = $this->periods();
        self::assertCount(2, $periods, 'still two periods: none created, none lost');
        self::assertSame('current', $periods[1]['state']);
        self::assertSame('closed', $periods[0]['state']);

        // No gap, no overlap, across every period the contract has.
        $this->assertContiguous($periods);

        // F4.3 step 2: the renewal after that one is not paid; it is late at its date.
        $second = $periods[1];
        $this->goTo($second['ends_at']->modify('-6 days'));
        $this->scheduler();
        $periods = $this->periods();
        self::assertCount(3, $periods);
        $this->assertContiguous($periods);
        $this->goTo($second['ends_at']->modify('-1 minute'));
        $this->scheduler();
        self::assertSame('active', $this->contractStatus(), 'not late before the due date');
        $this->goTo($second['ends_at']);
        $this->scheduler();
        self::assertSame('past_due', $this->contractStatus());
        $this->assertRights(true);
        $this->assertContiguous($this->periods());
    }

    // ---------------------------------------------------------------- R-05

    #[Test]
    public function r05_unpaid_then_grace_then_expiry_fall_exactly_on_their_dates(): void
    {
        $this->declareCustomer();
        $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
        $this->payOpenInvoice();

        $first = $this->periods()[0];
        $this->goTo($first['ends_at']->modify('-6 days'));
        $this->scheduler();
        $due = $first['ends_at'];
        $graceEnd = $due->modify('+7 days'); // the plan grants 7 days

        // One minute before the due date: still active.
        $this->goTo($due->modify('-1 minute'));
        $this->scheduler();
        self::assertSame('active', $this->contractStatus());

        // On the due date: late, rights kept during the grace.
        $this->goTo($due);
        $this->scheduler();
        self::assertSame('past_due', $this->contractStatus());
        $this->assertRights(true);

        // One minute before the end of the grace: still late, rights kept.
        $this->goTo($graceEnd->modify('-1 minute'));
        $this->scheduler();
        self::assertSame('past_due', $this->contractStatus());
        $this->assertRights(true);
        self::assertSame(0, $this->eventCount('subscription.expired'));

        // At the end of the grace: expired, rights closed, the project is told once.
        $this->goTo($graceEnd);
        $this->scheduler();
        $this->scheduler();
        self::assertSame('expired', $this->contractStatus());
        $this->assertRights(false);
        self::assertSame(1, $this->eventCount('subscription.expired'));
        $this->postJson('/v1/usage', $this->usage('after-expiry'), $this->api())->assertStatus(403);
    }

    // ---------------------------------------------------------------- R-09

    #[Test]
    public function r09_a_different_confirmed_amount_imputes_nothing_opens_an_exception_and_keeps_the_ledger_balanced(): void
    {
        $this->declareCustomer();
        $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

        $invoiceId = $this->scalar(DB::table('invoices')->where('status', 'open')->value('id'));
        $paymentId = $this->startPortalPayment($invoiceId);

        // F4.6: the provider confirms 15 000 for an invoice of 25 000.
        $this->replayProviderSuccess($paymentId, 15_000);
        $this->relay();

        $payment = DB::table('payments')->where('id', $paymentId)->first();
        self::assertNotNull($payment);
        self::assertSame('succeeded', $payment->status);
        self::assertSame('amount_mismatch', $payment->settlement_exception);

        // Nothing is imputed: the invoice is still open and owes everything.
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        self::assertNotNull($invoice);
        self::assertSame('open', $invoice->status);
        self::assertEquals(0, $invoice->amount_paid_minor);
        self::assertSame('failed', DB::table('invoice_payments')->where('invoice_id', $invoiceId)->value('status'));
        self::assertSame(0, $this->eventCount('invoice.paid'));
        $this->assertRights(false);

        // An exception of high severity is open for the finance team, and it
        // holds the money that was actually collected.
        $exception = DB::table('money_exceptions')->where('payment_id', $paymentId)->first();
        self::assertNotNull($exception);
        self::assertSame('open', $exception->status);
        self::assertSame('high', $exception->severity);
        self::assertEquals(15_000, $exception->booked_amount_minor);

        // The money sits on the exceptions account, and the ledger balances.
        self::assertSame(15_000, $this->balanceOf('payment_exceptions'));
        $this->assertLedgerBalanced();
        self::assertSame(self::PRICE, $this->balanceOfReceivable(), 'the receivable of the invoice is untouched');
    }

    // ---------------------------------------------------------------- R-10

    #[Test]
    public function r10_every_successful_payment_has_its_entry_and_the_trial_balance_is_nil_per_project_and_currency(): void
    {
        // Project A: an invoice paid from the portal, plus direct payments in two currencies.
        $this->declareCustomer();
        $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
        $this->payOpenInvoice();
        $this->directPayment($this->token, 5_000, 'XAF', 'succeeded');
        $this->directPayment($this->token, 1_250, 'USD', 'succeeded');
        $this->directPayment($this->token, 9_999, 'XAF', 'failed');

        // Project B: its own money, in its own books.
        [$otherToken, $otherProject] = $this->makeProject('Autre SaaS');
        $this->directPayment($otherToken, 7_000, 'XAF', 'succeeded');
        $this->directPayment($otherToken, 300, 'USD', 'canceled');
        $this->relay();

        // Every successful payment has exactly one settlement transaction in
        // its own project; no other payment has one.
        $payments = DB::table('payments')->get(['id', 'project_id', 'status']);
        self::assertGreaterThanOrEqual(5, $payments->count());
        foreach ($payments as $payment) {
            $transactions = DB::table('ledger_transactions')
                ->where('project_id', $payment->project_id)
                ->where('idempotency_key', $payment->id)
                ->count();
            self::assertSame($payment->status === 'succeeded' ? 1 : 0, $transactions, "payment {$this->str($payment->id)} ({$this->str($payment->status)})");
        }

        // The trial balance is nil per project AND currency.
        $this->assertLedgerBalanced();
        $perProject = DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->groupBy('ledger_transactions.project_id', 'ledger_entries.currency')
            ->get(['ledger_transactions.project_id', 'ledger_entries.currency']);
        $seen = $perProject->map(fn ($row): string => (is_string($row->project_id) ? $row->project_id : '').'/'.(is_string($row->currency) ? $row->currency : ''))->all();
        self::assertContains($this->projectId.'/XAF', $seen);
        self::assertContains($this->projectId.'/USD', $seen);
        self::assertContains($otherProject.'/XAF', $seen);
        self::assertNotContains($otherProject.'/USD', $seen, 'a canceled payment books nothing');

        // The hourly control agrees: no payment lacks its entry.
        $report = app(CheckPaymentInvariants::class)->check(48);
        self::assertTrue($report->isClean(), implode("\n", $report->violations));
    }

    // ---------------------------------------------------------------- R-13

    #[Test]
    public function r13_erasing_a_customer_leaves_no_personal_data_and_the_ledger_unchanged(): void
    {
        $this->declareCustomer();
        $this->postJson('/v1/subscriptions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
        $this->payOpenInvoice();
        $cookie = $this->openPortal();
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk();
        $this->relay();

        $ledgerBefore = $this->ledgerFingerprint();
        $invoicesBefore = DB::table('invoices')->orderBy('id')->get(['id', 'number', 'status', 'total_minor', 'amount_paid_minor'])->all();
        self::assertNotSame([], $invoicesBefore);

        $this->postJson('/v1/customers/AE0001/erase', [], $this->api())->assertOk();
        $this->relay();

        // The identity is gone: nothing readable, nothing blind-indexed.
        $row = DB::table('customers')->where('external_ref', 'AE0001')->first();
        self::assertNotNull($row);
        self::assertSame('erased', $row->status);
        foreach (['email_ciphertext', 'email_bidx', 'phone_ciphertext', 'phone_bidx'] as $column) {
            self::assertNull($row->{$column}, "customers.{$column} must be empty after the erasure");
        }

        // Not one column of one table still holds the person's name, address or number.
        self::assertSame([], $this->tablesHolding(['Kamga Soleil', 'kamga.soleil@client.example', '677001122']));

        // ADR-0094 §7: an ISSUED invoice is a document, its frozen snapshot survives the
        // erasure — but only as ciphertext, never as a readable value (checked above by the
        // sweep, and here by the cipher prefix).
        foreach (DB::table('invoices')->pluck('customer_snapshot') as $snapshot) {
            $decoded = json_decode($this->str($snapshot), true);
            self::assertIsArray($decoded);
            foreach ($decoded as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_ciphertext')) {
                    self::assertIsString($value);
                    self::assertStringStartsWith('pii:v1:', $value);
                }
            }
        }

        // The contract is over, the portal is closed.
        self::assertContains($this->contractStatus(), ['canceled', 'expired']);
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertStatus(401);
        $entitlements = $this->getJson('/v1/customers/AE0001/entitlements', $this->api());
        self::assertTrue($entitlements->baseResponse->getStatusCode() === 404 || $entitlements->json('active') === false, 'an erased customer has no right left');
        self::assertSame(1, $this->eventCount('customer.erased'));
        self::assertSame(1, $this->eventCount('subscription.canceled'));

        // The books and the invoices are exactly what they were.
        self::assertSame($ledgerBefore, $this->ledgerFingerprint(), 'the ledger is unchanged by an erasure');
        self::assertEquals($invoicesBefore, DB::table('invoices')->orderBy('id')->get(['id', 'number', 'status', 'total_minor', 'amount_paid_minor'])->all());
        $this->assertLedgerBalanced();
    }

    // ---------------------------------------------------------------- R-14

    #[Test]
    public function r14_a_suspended_project_refuses_v1_but_provider_callbacks_are_still_processed(): void
    {
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $this->projectId, providerCode: 'my-coolpay', publicKey: 'pub-recette-14', privateKey: 'recette-private-key',
        ));
        Http::fake(['my-coolpay.com/api/pub-recette-14/payin' => Http::response(['status' => 'success', 'transaction_ref' => 'tx-recette-14', 'action' => 'PENDING'], 201)]);
        config(['payment.simulator.enabled' => false]);

        $paymentId = $this->str($this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo', 'amount' => 4_000, 'currency' => 'XAF', 'reason' => 'Recette R-14',
        ], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('id'));

        // The administrator suspends the project.
        app(UpdateProject::class)->update(
            new UpdateProjectCommand($this->projectId, 'Mon Auto-École', 'suspended', 'Recette R-14'),
            actor: 'service:test',
            correlationId: null,
        );

        // /v1 is refused with the plain reason, on reads and on writes.
        $this->getJson('/v1/whoami', $this->api())->assertStatus(403)->assertJsonPath('type', fn (mixed $type): bool => is_string($type) && str_contains($type, 'project-suspended'));
        $this->getJson("/v1/payments/{$paymentId}", $this->api())->assertStatus(403);
        $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo', 'amount' => 100, 'currency' => 'XAF', 'reason' => 'refused',
        ], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(403);

        // The provider's callback is not a /v1 call: it is still processed
        // and the money it announces is booked.
        $fields = [
            'application' => 'pub-recette-14',
            'app_transaction_ref' => $this->str(DB::table('payments')->where('id', $paymentId)->value('idempotency_key')),
            'transaction_ref' => 'tx-recette-14',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 4_000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_MTN',
            'transaction_status' => 'SUCCESS',
        ];
        $fields['signature'] = md5('tx-recette-14'.'PAYIN'.'4000'.'XAF'.'CM_MTN'.'recette-private-key');
        $this->withServerVariables(['REMOTE_ADDR' => '15.236.140.89'])->postJson('/webhooks/my-coolpay', $fields)->assertOk();

        self::assertSame('succeeded', DB::table('payments')->where('id', $paymentId)->value('status'));
        self::assertSame(1, DB::table('ledger_transactions')->where('project_id', $this->projectId)->where('idempotency_key', $paymentId)->count());
        $this->assertLedgerBalanced();

        // The project is still suspended afterwards.
        $this->getJson('/v1/whoami', $this->api())->assertStatus(403);
    }

    // ------------------------------------------------------------------ set-up

    protected function setUp(): void
    {
        parent::setUp();

        // Une URL unique par test : deux tests qui partagent la meme URL
        // ne peuvent pas se marcher dessus (le projet, lui, est neuf).
        $this->webhook = self::WEBHOOK.'/'.uniqid();

        $this->clock = new class(new DateTimeImmutable('2026-11-01T08:00:00Z')) implements Clock
        {
            public function __construct(public DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };
        $clock = $this->clock;
        $this->app->bind(Clock::class, static fn (): Clock => $clock);

        config(['payment.simulator.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([$this->webhook => Http::response('', 204)]);

        [$this->token, $this->projectId] = $this->makeProject('Mon Auto-École');

        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $this->projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $this->projectId, planCode: 'pro', priceMinor: self::PRICE, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 5, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $this->projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));

        $this->postJson('/v1/webhook-endpoints', ['url' => $this->webhook, 'events' => ['*']], $this->api())->assertStatus(201);
        $this->putJson('/v1/portal/settings', [
            'display_name' => 'Mon Auto-École', 'allow_plan_change' => true, 'allow_self_cancel' => true,
            'allowed_return_urls' => ['https://mon-saas.example.cm/compte'],
        ], $this->api())->assertOk();
    }

    // ------------------------------------------------------------------ steps

    /**
     * @return array{0: string, 1: string} [bearer token, project id]
     */
    private function makeProject(string $name): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand($name.' (org)'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, $name), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ApiKeyScopes::ALL), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    private function declareCustomer(): void
    {
        $this->putJson('/v1/customers/AE0001', [
            'display_name' => 'Kamga Soleil',
            'email' => 'kamga.soleil@client.example',
            'phone' => '+237677001122',
        ], $this->api())->assertSuccessful();
    }

    /**
     * The portal link is exchanged for the session cookie, as a browser does.
     */
    private function openPortal(): string
    {
        $response = $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->api())->assertStatus(201);
        $link = $response->json('url');
        self::assertIsString($link);

        $enter = $this->get((string) parse_url($link, PHP_URL_PATH))->assertRedirect();
        $cookie = collect($enter->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');
        self::assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    /**
     * The customer starts paying the latest open invoice from the portal.
     * Returns the id of the payment the platform started.
     */
    private function startPortalPayment(?string $invoiceId = null): string
    {
        $cookie = $this->openPortal();
        $invoiceId ??= $this->scalar(DB::table('invoices')->where('project_id', $this->projectId)->where('status', 'open')->orderByDesc('created_at')->value('id'));
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get("/portal/invoices/{$invoiceId}"));
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $csrf);
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $html, $nonce);

        $this->withUnencryptedCookie('portal', $cookie)->post("/portal/invoices/{$invoiceId}/pay", [
            '_csrf' => $csrf[1] ?? '', 'channel' => 'mtn_momo', 'phone' => '+237677001122', 'nonce' => $nonce[1] ?? '',
        ])->assertOk();

        return $this->scalar(DB::table('invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'pending')->value('payment_id'));
    }

    /**
     * Pays the latest open invoice from the portal; the simulator confirms
     * the requested amount. Returns the payment id.
     */
    private function payOpenInvoice(): string
    {
        $paymentId = $this->startPortalPayment();
        $this->postJson("/v1/payments/{$paymentId}/simulate", ['status' => 'succeeded'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->relay();

        return $paymentId;
    }

    /**
     * A provider callback for a simulated payment, with the amount the
     * provider says it collected (what a real callback carries).
     */
    private function replayProviderSuccess(string $paymentId, int $confirmedAmount): void
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        self::assertNotNull($payment);

        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: $this->str($payment->provider_code),
            transactionRef: $this->str($payment->provider_transaction_ref),
            appTransactionRef: $this->str($payment->idempotency_key),
            status: PaymentStatus::Succeeded,
            confirmedAmountMinor: $confirmedAmount,
            confirmedCurrency: $this->str($payment->currency),
        ), 'provider_callback');
    }

    private function directPayment(string $token, int $amount, string $currency, string $outcome): void
    {
        $api = ['Authorization' => "Bearer {$token}"];
        $id = $this->str($this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo', 'amount' => $amount, 'currency' => $currency, 'reason' => 'Recette',
        ], $api + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('id'));

        $this->postJson("/v1/payments/{$id}/simulate", ['status' => $outcome], $api + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    private function assertRights(bool $active): void
    {
        $this->getJson('/v1/customers/AE0001/entitlements', $this->api())->assertOk()->assertJsonPath('active', $active);
    }

    private function contractStatus(): string
    {
        return $this->scalar(DB::table('subscriptions')->where('project_id', $this->projectId)->orderByDesc('created_at')->value('status'));
    }

    private function scheduler(): void
    {
        app(AdvanceSubscriptionsUseCase::class)->advance();
        $this->relay();
    }

    private function relay(): void
    {
        app(RelayOutboxEvents::class)->relay(500);
        app(RelayOutboxEvents::class)->relay(500);
    }

    private function goTo(DateTimeImmutable $moment): void
    {
        $property = (new ReflectionObject($this->clock))->getProperty('moment');
        $property->setValue($this->clock, $moment);
    }

    private function eventCount(string $type): int
    {
        return DB::table('event_outbox')->where('project_id', $this->projectId)->where('type', $type)->count();
    }

    /**
     * The event types the project's endpoint received, in delivery order.
     *
     * @return list<string>
     */
    private function deliveredTypes(): array
    {
        $types = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->url() !== $this->webhook) {
                continue;
            }
            $body = json_decode($request->body(), true);
            if (is_array($body) && is_string($body['type'] ?? null)) {
                $types[] = $body['type'];
            }
        }

        return $types;
    }

    /**
     * @return list<array{seq: int, state: string, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}>
     */
    private function periods(): array
    {
        $periods = [];
        foreach (DB::table('subscription_periods')->where('project_id', $this->projectId)->orderBy('seq')->get() as $row) {
            self::assertIsInt($row->seq);
            $periods[] = [
                'seq' => $row->seq,
                'state' => $this->str($row->state),
                'starts_at' => new DateTimeImmutable($this->str($row->starts_at)),
                'ends_at' => new DateTimeImmutable($this->str($row->ends_at)),
            ];
        }

        return $periods;
    }

    /**
     * @param list<array{seq: int, state: string, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}> $periods
     */
    private function assertContiguous(array $periods): void
    {
        for ($i = 1, $n = count($periods); $i < $n; $i++) {
            self::assertEquals($periods[$i - 1]['ends_at'], $periods[$i]['starts_at'], "period {$periods[$i]['seq']} starts when {$periods[$i - 1]['seq']} ends: no gap, no overlap");
            self::assertSame($periods[$i - 1]['seq'] + 1, $periods[$i]['seq']);
        }
    }

    // ------------------------------------------------------------------ ledger

    /**
     * Debits equal credits in every transaction, and in every project and currency.
     */
    private function assertLedgerBalanced(): void
    {
        $perTransaction = DB::table('ledger_entries')
            ->groupBy('transaction_id')
            ->havingRaw("SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END) <> 0")
            ->get(['transaction_id']);
        self::assertCount(0, $perTransaction, 'every ledger transaction balances to zero');

        $net = DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->groupBy('ledger_transactions.project_id', 'ledger_entries.currency')
            ->selectRaw("ledger_transactions.project_id, ledger_entries.currency, SUM(CASE WHEN ledger_entries.direction = 'debit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) AS net")
            ->get();
        foreach ($net as $row) {
            self::assertEquals(0, $row->net, "trial balance of {$this->str($row->project_id)} in {$this->str($row->currency)} is nil");
        }
    }

    /**
     * Net credit of the accounts of a kind in this project (what the ledger holds there).
     */
    private function balanceOf(string $kind): int
    {
        return $this->netOf($kind, 'credit');
    }

    /**
     * What customers still owe: the debit balance of the receivable account.
     */
    private function balanceOfReceivable(): int
    {
        return $this->netOf('customer_receivable', 'debit');
    }

    private function netOf(string $kind, string $positiveSide): int
    {
        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.project_id', $this->projectId)
            ->where('ledger_accounts.kind', $kind)
            ->get(['ledger_entries.direction', 'ledger_entries.amount_minor']);

        $net = 0;
        foreach ($rows as $row) {
            self::assertIsInt($row->amount_minor);
            $net += $row->direction === $positiveSide ? $row->amount_minor : -$row->amount_minor;
        }

        return $net;
    }

    private function ledgerFingerprint(): string
    {
        $rows = DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.id', 'ledger_entries.account_id', 'ledger_entries.direction', 'ledger_entries.amount_minor', 'ledger_entries.currency', 'ledger_transactions.type']);

        return md5(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * The tables, anywhere in the database, with a row whose text holds one of the needles.
     *
     * @param list<string> $needles
     * @return list<string>
     */
    private function tablesHolding(array $needles): array
    {
        $found = [];
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->where('table_name', '!=', 'migrations')
            ->pluck('table_name');

        foreach ($tables as $table) {
            $table = $this->str($table);

            foreach ($needles as $needle) {
                // The whole row as text: any column, whatever its type (json, uuid, text…).
                $hit = DB::table($table)->whereRaw('"'.$table.'"::text ILIKE ?', ['%'.$needle.'%'])->exists(); // @phpstan-ignore argument.type
                if ($hit) {
                    $found[] = "{$table} ~ {$needle}";
                }
            }
        }

        return $found;
    }

    // ------------------------------------------------------------------ misc

    /**
     * @return array<string, string>
     */
    private function api(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(string $key): array
    {
        return ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => $key];
    }

    private function scalar(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html($response): string
    {
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }
}
