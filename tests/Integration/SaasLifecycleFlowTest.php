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
 * The PROCESS of a SaaS customer over time (ADR-0072 §2, §4, ADR-0084 §2–3),
 * with the clock driven by the test — not just the first purchase:
 *
 *   day 0   subscribes, first invoice waits, no right yet
 *   day 0   pays → active, rights open, quota counts
 *   day 24  the renewal invoice is issued ahead of the period (renewal_due)
 *   day 31  the period turned over unpaid → past_due, rights KEPT during the grace
 *   day 39  the grace is over → expired, rights CLOSED, quota refuses
 *   day 40  the customer renews from the portal, pays → active again
 *
 * and the happy variant where the renewal is paid inside the grace.
 */
final class SaasLifecycleFlowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://mon-saas.example.cm/webhooks/core';

    private object $clock;

    private string $token = '';

    private string $projectId = '';

    #[Test]
    public function an_unpaid_renewal_runs_through_grace_then_expiry_and_the_customer_comes_back(): void
    {
        // ---- day 0: one call, the contract waits for its first payment
        $this->openPortal(['customer' => ['display_name' => 'Auto-école Soleil', 'phone' => '+237657467941'], 'plan_code' => 'pro']);
        self::assertSame('incomplete', $this->contractStatus());
        $this->assertRights(false);
        $this->postJson('/v1/usage', $this->usage('s0'), $this->api())->assertStatus(403);

        // ---- day 0: first payment opens the contract
        $this->payOpenInvoice();
        self::assertSame('active', $this->contractStatus());
        $this->assertRights(true);
        $this->postJson('/v1/usage', $this->usage('s1'), $this->api())->assertStatus(201);

        // ---- day 24: the next period is prepared ahead; the renewal invoice is open and unpaid
        $this->goTo('2026-11-25T08:00:00Z');
        $this->scheduler();
        self::assertSame(2, DB::table('invoices')->where('project_id', $this->projectId)->count(), 'the renewal invoice was issued ahead of the period');
        self::assertSame('open', DB::table('invoices')->orderByDesc('created_at')->value('status'));
        self::assertSame('active', $this->contractStatus());
        $this->assertRights(true);

        // ---- day 31: the period turned over without payment → past_due, rights KEPT (grace)
        $this->goTo('2026-12-02T08:00:00Z');
        $this->scheduler();
        self::assertSame('past_due', $this->contractStatus());
        $this->assertRights(true);
        $this->postJson('/v1/usage', $this->usage('s2'), $this->api())->assertStatus(201);

        // ---- day 40: the grace (7 days) is over → expired, rights CLOSED
        $this->goTo('2026-12-10T08:00:00Z');
        $this->scheduler();
        self::assertSame('expired', $this->contractStatus());
        $this->assertRights(false);
        $this->postJson('/v1/usage', $this->usage('s3'), $this->api())->assertStatus(403);

        // ---- the customer comes back from the portal and renews as a new contract
        $cookie = $this->openPortal([]);
        $page = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal/subscriptions'));
        self::assertStringContainsString('Renouveler mon abonnement', $page);
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $csrf);
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $page, $nonce);
        $expired = $this->scalar(DB::table('subscriptions')->where('status', 'expired')->value('id'));
        $this->withUnencryptedCookie('portal', $cookie)->post("/portal/subscriptions/{$expired}/renew", ['_csrf' => $csrf[1] ?? '', 'nonce' => $nonce[1] ?? ''])
            ->assertRedirect('/portal/invoices');

        $this->payOpenInvoice();
        $this->assertRights(true);
        $this->postJson('/v1/usage', $this->usage('s4'), $this->api())->assertStatus(201);

        // ---- the SaaS was told at every step, in the vocabulary of ADR-0084 §3
        $this->relay();
        $types = DB::table('event_outbox')->pluck('type')->all();
        foreach (['subscription.created', 'invoice.paid', 'subscription.activated', 'subscription.past_due', 'subscription.expired'] as $expected) {
            self::assertContains($expected, $types, "event {$expected} was published");
        }
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => $r->url() === self::WEBHOOK && str_contains($r->body(), '"type":"subscription.expired"'));
    }

    #[Test]
    public function a_renewal_paid_inside_the_grace_keeps_the_customer_and_never_expires(): void
    {
        $this->openPortal(['customer' => ['display_name' => 'Auto-école Lune'], 'plan_code' => 'pro']);
        $this->payOpenInvoice();

        $this->goTo('2026-12-02T08:00:00Z');
        $this->scheduler();
        self::assertSame('past_due', $this->contractStatus());

        // Pays on day 35, still inside the grace.
        $this->goTo('2026-12-06T08:00:00Z');
        $this->payOpenInvoice();
        self::assertSame('active', $this->contractStatus());

        // The grace date passes: nothing expires.
        $this->goTo('2026-12-12T08:00:00Z');
        $this->scheduler();
        self::assertSame('active', $this->contractStatus());
        $this->assertRights(true);
    }

    protected function setUp(): void
    {
        parent::setUp();

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
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Mon SaaS'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project;
        $this->projectId = $project->id;
        $this->token = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ApiKeyScopes::ALL), actor: 'service:test', correlationId: null)->plaintextToken;

        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $this->projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $this->projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 5, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $this->projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));

        $this->postJson('/v1/webhook-endpoints', ['url' => self::WEBHOOK, 'events' => ['*']], $this->api())->assertStatus(201);
        $this->putJson('/v1/portal/settings', [
            'display_name' => 'Mon Auto-École', 'allow_plan_change' => true, 'allow_self_cancel' => true,
            'allowed_return_urls' => ['https://mon-saas.example.cm/compte'],
        ], $this->api())->assertOk();
    }

    // ------------------------------------------------------------------ steps

    /**
     * One call opens the portal (declaring the customer / subscribing it when
     * asked) and the browser exchanges the link — the way a SaaS does it.
     *
     * @param array<string, mixed> $extra
     */
    private function openPortal(array $extra): string
    {
        $response = $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'] + $extra, $this->api())->assertStatus(201);
        $link = $response->json('url');
        self::assertIsString($link);

        $enter = $this->get((string) parse_url($link, PHP_URL_PATH))->assertRedirect();
        $cookie = collect($enter->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');
        self::assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    /**
     * The customer pays the open invoice from the portal; the operator (the
     * simulator on staging) confirms; the outbox runs.
     */
    private function payOpenInvoice(): void
    {
        $cookie = $this->openPortal([]);
        $invoiceId = $this->scalar(DB::table('invoices')->where('project_id', $this->projectId)->where('status', 'open')->orderByDesc('created_at')->value('id'));
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get("/portal/invoices/{$invoiceId}"));
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $csrf);
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $html, $nonce);

        $this->withUnencryptedCookie('portal', $cookie)->post("/portal/invoices/{$invoiceId}/pay", [
            '_csrf' => $csrf[1] ?? '', 'channel' => 'mtn_momo', 'phone' => '+237657467941', 'nonce' => $nonce[1] ?? '',
        ])->assertOk();

        $paymentId = $this->scalar(DB::table('invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'pending')->value('payment_id'));
        $this->postJson("/v1/payments/{$paymentId}/simulate", ['status' => 'succeeded'], $this->api() + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->relay();
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

    private function goTo(string $moment): void
    {
        $property = (new ReflectionObject($this->clock))->getProperty('moment');
        $property->setValue($this->clock, new DateTimeImmutable($moment));
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(string $key): array
    {
        return ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => $key];
    }

    /**
     * @return array<string, string>
     */
    private function api(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function scalar(mixed $value): string
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
