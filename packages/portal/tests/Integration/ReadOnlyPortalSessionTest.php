<?php

declare(strict_types=1);

namespace Core\Portal\Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\GrantComplimentaryPeriod;
use Core\Billing\Contracts\GrantComplimentaryPeriodCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0075 §1 — the read-only portal session.
 *
 * The support operator must see **exactly** what the customer sees, and
 * touch nothing. The `read` flow opens the very same portal with every
 * gesture removed: the pages show the subscription, the invoices, the
 * usage and the profile; the mutating gestures are refused by the code
 * that performs them, so that a hand-crafted POST fails exactly like the
 * button that is not drawn.
 */
final class ReadOnlyPortalSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_read_session_opens_the_portal_and_shows_what_the_customer_sees(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001', 'Auto-école Soleil');
        $this->plan($projectId, 'pro', 25_000);
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');
        $this->subscription($projectId, 'AE0001', 'pro');

        $cookie = $this->openSession($token, 'AE0001', 'read');
        $get = fn (string $path) => $this->withUnencryptedCookie('portal', $cookie)->get($path);

        // The dashboard, the subscription, the invoices, the usage, the
        // profile: the same pages, the same data as a `manage` session.
        $get('/portal')->assertOk()->assertSee('Auto-école Soleil')->assertSee('Votre abonnement');
        $get('/portal/subscriptions')->assertOk()->assertSee('Pro')->assertSee('Abonné depuis');
        $get('/portal/invoices')->assertOk()->assertSee((string) $invoice->number);
        $get('/portal/invoices/'.$invoice->id)->assertOk()->assertSee('Abonnement Soleil');
        $get('/portal/usage')->assertOk()->assertSee('students');
        $get('/portal/profile')->assertOk()->assertSee('Auto-école Soleil')->assertSee('AE0001');
    }

    #[Test]
    public function every_mutating_gesture_is_refused_on_a_read_session(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->selfService($token);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->plan($projectId, 'pro', 25_000);
        $this->plan($projectId, 'premium', 60_000);
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement');
        $subscription = $this->subscription($projectId, 'AE0001', 'pro');

        $cookie = $this->openSession($token, 'AE0001', 'read');
        $csrf = $this->csrfOf($cookie);

        $gestures = [
            ['/portal/plans/premium/subscribe', ['nonce' => str_repeat('ab', 16)], 'la souscription'],
            ['/portal/subscriptions/'.$subscription->id.'/cancel', [], 'la résiliation'],
            ['/portal/subscriptions/'.$subscription->id.'/change-plan', ['plan' => 'premium'], 'le changement'],
            ['/portal/subscriptions/'.$subscription->id.'/keep', [], 'la reprise du renouvellement'],
            ['/portal/subscriptions/'.$subscription->id.'/renew', ['nonce' => str_repeat('cd', 16)], 'le renouvellement'],
            ['/portal/invoices/'.$invoice->id.'/pay', ['channel' => 'mtn_momo', 'phone' => '+237657467941', 'nonce' => str_repeat('ef', 16)], 'le paiement'],
        ];

        foreach ($gestures as [$path, $body, $what]) {
            $response = $this->withUnencryptedCookie('portal', $cookie)->post($path, ['_csrf' => $csrf] + $body);

            // The refusal, not a crash and not a silent success.
            $response->assertStatus(422);
            $response->assertSee('consultation seule');
            $response->assertSee($what);
        }

        // Nothing moved: no contract, no cancellation, no plan change, no payment.
        self::assertSame(1, DB::table('subscriptions')->count());
        self::assertFalse((bool) DB::table('subscriptions')->where('id', $subscription->id)->value('cancel_at_period_end'));
        self::assertNull(DB::table('subscriptions')->where('id', $subscription->id)->value('pending_plan_version_id'));
        self::assertSame(0, DB::table('invoice_payments')->count());
    }

    #[Test]
    public function a_read_session_shows_no_gesture_at_all(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->selfService($token);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->plan($projectId, 'pro', 25_000);
        $this->plan($projectId, 'premium', 60_000);
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement');
        $this->subscription($projectId, 'AE0001', 'pro');
        $this->routePayment();

        $cookie = $this->openSession($token, 'AE0001', 'read');
        $get = fn (string $path) => $this->withUnencryptedCookie('portal', $cookie)->get($path);

        // The raw HTML: the « Payer » of the dashboard is a badge, and it
        // must not be drawn either.
        $get('/portal')->assertOk()->assertDontSee('>Payer<', false);
        $get('/portal/subscriptions')->assertOk()->assertDontSee('Ne pas renouveler')->assertDontSee('Renouveler mon abonnement');
        $get('/portal/plans')->assertOk()->assertDontSee('Souscrire')->assertDontSee('Choisir cette offre');
        $get('/portal/invoices/'.$invoice->id)->assertOk()->assertDontSee('Payer cette facture');

        // The session says what it is.
        $get('/portal')->assertOk()->assertSee('Consultation seule');
    }

    #[Test]
    public function a_manage_session_still_acts_exactly_as_before(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->selfService($token);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->plan($projectId, 'pro', 25_000);
        $this->plan($projectId, 'premium', 60_000);
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement');
        $subscription = $this->subscription($projectId, 'AE0001', 'pro');
        $this->routePayment();

        $cookie = $this->openSession($token, 'AE0001', 'manage');
        $csrf = $this->csrfOf($cookie);

        // The gestures are drawn…
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk()->assertSee('>Payer<', false);
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/subscriptions')->assertOk()->assertSee('Ne pas renouveler');
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/plans')->assertOk()->assertSee('Choisir cette offre');
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices/'.$invoice->id)->assertOk()->assertSee('Payer cette facture');

        // …and they work.
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/cancel', ['_csrf' => $csrf])
            ->assertRedirect('/portal/subscriptions');
        self::assertTrue((bool) DB::table('subscriptions')->where('id', $subscription->id)->value('cancel_at_period_end'));

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/keep', ['_csrf' => $csrf])
            ->assertRedirect('/portal/subscriptions');
        self::assertFalse((bool) DB::table('subscriptions')->where('id', $subscription->id)->value('cancel_at_period_end'));

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/change-plan', ['_csrf' => $csrf, 'plan' => 'premium'])
            ->assertRedirect('/portal/subscriptions');
        self::assertNotNull(DB::table('subscriptions')->where('id', $subscription->id)->value('pending_plan_version_id'));
    }

    #[Test]
    public function the_api_accepts_the_read_flow_and_the_scope_rules_do_not_change(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'read'], $this->auth($token))->assertStatus(201);

        // A flow the portal does not know is still refused.
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'destroy'], $this->auth($token))->assertStatus(422);

        // A key without `portal:write` opens nothing, read flow or not.
        [$other] = $this->project(['customers:read']);
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'read'], $this->auth($other))->assertStatus(403);

        // The scopes of the payload do not change either: `plan_code` still
        // needs `subscriptions:write`…
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'read', 'plan_code' => 'pro'], $this->auth($token))->assertStatus(403);

        // …and a read session subscribes nobody, whoever asks.
        [$writer] = $this->project(['portal:write', 'subscriptions:write']);
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'read', 'plan_code' => 'pro'], $this->auth($writer))->assertStatus(422);
        self::assertSame(0, DB::table('subscriptions')->count());
    }

    #[Test]
    public function the_audit_entry_records_the_flow(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'flow' => 'read'], $this->auth($token))->assertStatus(201);

        $context = DB::table('audit_log')
            ->where('action', 'portal.session_opened')
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->value('context');

        self::assertIsString($context);
        $decoded = json_decode($context, true);
        self::assertIsArray($decoded);
        self::assertSame('read', $decoded['flow'] ?? null);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string}
     */
    private function project(array $scopes): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, $scopes), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * The project lets its customers act alone — otherwise a refusal would
     * prove nothing about the read flow.
     */
    private function selfService(string $token): void
    {
        $this->putJson('/v1/portal/settings', [
            'display_name' => 'Mon SaaS',
            'allow_plan_change' => true,
            'allow_self_cancel' => true,
        ], $this->auth($token))->assertOk();
    }

    private function customer(string $projectId, string $ref, string $name = 'Client'): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: $name, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function plan(string $projectId, string $code = 'pro', int $price = 25_000, string $visibility = 'public'): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: $code, name: ucfirst($code), description: null, visibility: $visibility,
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: $code, priceMinor: $price, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 100, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: $code, version: $version->version, actor: 'test', correlationId: null,
        ));
    }

    private function subscription(string $projectId, string $ref, string $planCode): SubscriptionView
    {
        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $ref, planCode: $planCode, trialDays: null, startAt: null,
            idempotencyKey: 'sub-'.$ref.'-'.$planCode, actor: 'test', correlationId: null,
        ));

        // A contract born without a period is « incomplete »: it holds no
        // right yet. The complimentary period brings it to life, so that
        // the portal shows a live contract — and the quotas it grants.
        app(GrantComplimentaryPeriod::class)->grant(new GrantComplimentaryPeriodCommand(
            projectId: $projectId, subscriptionId: $subscription->id, days: 30, reason: 'test', actor: 'test', correlationId: null,
        ));

        return $subscription;
    }

    private function invoice(string $projectId, string $ref, string $description): InvoiceView
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: $ref,
            lines: [new InvoiceLineInput(description: $description, quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test', correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId, invoiceId: $draft->id, actor: 'test', correlationId: null,
        ));
    }

    /**
     * A platform that routes mobile money: without it the portal offers no
     * rail at all, and the invoice page shows no payment form.
     */
    private function routePayment(): void
    {
        $this->app->instance(PaymentProviderRouter::class, new class implements PaymentProviderRouter
        {
            public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode
            {
                return ProviderCode::fromString('test-provider');
            }
        });
    }

    private function openSession(string $token, string $ref, string $flow = 'manage'): string
    {
        $url = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => $ref, 'flow' => $flow], $this->auth($token))->assertStatus(201), 'url');
        $response = $this->get((string) parse_url($url, PHP_URL_PATH));
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        return $cookie?->getValue() ?? '';
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function jsonString($response, string $key): string
    {
        $value = $response->json($key);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html($response): string
    {
        // Larastan models TestResponse's forwarded getContent() as static.
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }

    private function csrfOf(string $cookie): string
    {
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal'));
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);

        return $m[1] ?? '';
    }
}
