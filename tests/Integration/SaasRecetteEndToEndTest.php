<?php

declare(strict_types=1);

namespace Tests\Integration;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lot 17 — la recette de bout en bout du commerce SaaS (ADR-0084 §2, ADR-0097).
 *
 * Elle rejoue ce que fait un vrai SaaS branché au Core, du premier appel au
 * blocage par quota, sans prestataire réel : le simulateur de paiement du
 * staging (ADR-0066) joue le rôle de l'opérateur Mobile Money.
 *
 *   1. le SaaS enregistre son récepteur d'événements ;
 *   2. UN appel ouvre le portail (déclare le tenant, l'abonne) ;
 *   3. le tenant n'a aucun droit tant qu'il n'a pas payé ;
 *   4. il paie depuis le portail ; l'opérateur confirme ;
 *   5. la facture est soldée, l'abonnement s'active, le SaaS est prévenu ;
 *   6. le tenant consomme son quota ; au-delà, c'est refusé sans rien écrire ;
 *   7. il résilie à la fin de la période depuis le portail.
 *
 * App-level à dessein (G3c) : le scénario orchestre plusieurs modules.
 */
final class SaasRecetteEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const SAAS_WEBHOOK = 'https://mon-saas.example.cm/webhooks/core';

    #[Test]
    public function a_saas_goes_from_first_call_to_paid_subscription_to_quota_limit(): void
    {
        config(['payment.simulator.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([self::SAAS_WEBHOOK => Http::response('', 204)]);

        [$token, $projectId] = $this->project();
        $api = ['Authorization' => "Bearer {$token}"];
        $this->publishPlan($projectId);

        // 1. The SaaS registers the endpoint that will tell it about changes.
        $this->postJson('/v1/webhook-endpoints', [
            'url' => self::SAAS_WEBHOOK,
            'events' => ['subscription.activated', 'invoice.paid', 'usage.limit_reached'],
        ], $api)->assertStatus(201);

        $this->putJson('/v1/portal/settings', [
            'display_name' => 'Mon Auto-École',
            'primary_color' => '#0f766e',
            'allowed_return_urls' => ['https://mon-saas.example.cm/compte'],
            'allow_self_cancel' => true,
        ], $api)->assertOk();

        // 2. ONE call: the tenant is declared, subscribed, and a link comes back.
        $opened = $this->postJson('/v1/portal/sessions', [
            'customer_ref' => 'AE0001',
            'customer' => ['display_name' => 'Auto-école Soleil', 'email' => 'direction@soleil.cm', 'phone' => '+237657467941'],
            'plan_code' => 'pro',
            'return_url' => 'https://mon-saas.example.cm/compte',
        ], $api)->assertStatus(201)->assertJsonPath('customer_created', true)->assertJsonPath('subscription_created', true);

        // 3. Not paid yet: no right, and the quota refuses.
        $this->getJson('/v1/customers/AE0001/entitlements', $api)->assertOk()->assertJsonPath('active', false);
        $this->postJson('/v1/usage', ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'early'], $api)
            ->assertStatus(403);

        // 4. The tenant opens the portal and pays its first invoice.
        $link = $opened->json('url');
        self::assertIsString($link);
        $enter = $this->get((string) parse_url($link, PHP_URL_PATH))->assertRedirect('/portal');
        $cookie = (string) collect($enter->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal')?->getValue();

        $invoiceId = $this->scalar(DB::table('invoices')->where('project_id', $projectId)->where('status', 'open')->value('id'));
        self::assertNotSame('', $invoiceId, 'The first period carries an open invoice.');

        $home = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal'));
        self::assertStringContainsString('À régler', $home);
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $home, $csrf);

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/invoices/'.$invoiceId.'/pay', [
            '_csrf' => $csrf[1] ?? '', 'channel' => 'mtn_momo', 'phone' => '+237657467941', 'nonce' => bin2hex(random_bytes(16)),
        ])->assertOk();

        $paymentId = $this->scalar(DB::table('invoice_payments')->where('invoice_id', $invoiceId)->value('payment_id'));
        self::assertNotSame('', $paymentId, 'The portal started a payment through the platform endpoint.');

        // The operator confirms (simulator on staging).
        $this->postJson("/v1/payments/{$paymentId}/simulate", ['status' => 'succeeded'], $api + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('status', 'succeeded');
        app(RelayOutboxEvents::class)->relay(100);
        app(RelayOutboxEvents::class)->relay(100);

        // 5. The invoice is settled, the contract is alive, the SaaS was told.
        self::assertSame('paid', DB::table('invoices')->where('id', $invoiceId)->value('status'));
        $this->getJson('/v1/customers/AE0001/entitlements', $api)->assertOk()->assertJsonPath('active', true)->assertJsonPath('plan_code', 'pro');
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SAAS_WEBHOOK && str_contains($r->body(), '"type":"invoice.paid"'));
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SAAS_WEBHOOK && str_contains($r->body(), '"type":"subscription.activated"'));

        // 6. The tenant uses its quota (3 students); the fourth is refused and writes nothing.
        foreach ([1, 2, 3] as $n) {
            $this->postJson('/v1/usage', ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => "student-{$n}"], $api)
                ->assertStatus(201);
        }
        $this->postJson('/v1/usage', ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'student-4'], $api)
            ->assertStatus(429);
        $this->postJson('/v1/usage', ['customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'student-1'], $api)
            ->assertStatus(200)->assertJsonPath('status', 'replayed');
        self::assertSame(3, DB::table('usage_records')->count());

        app(RelayOutboxEvents::class)->relay(100);
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '"type":"usage.limit_reached"'));

        // 7. The tenant stops the contract at the end of its period, from the portal.
        $subscriptionId = $this->scalar(DB::table('subscriptions')->where('project_id', $projectId)->value('id'));
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscriptionId.'/cancel', ['_csrf' => $csrf[1] ?? ''])
            ->assertRedirect('/portal/subscriptions');
        self::assertTrue((bool) DB::table('subscriptions')->where('id', $subscriptionId)->value('cancel_at_period_end'));

        // Rights stay until the period ends.
        $this->getJson('/v1/customers/AE0001/entitlements', $api)->assertJsonPath('active', true);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Mon SaaS'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ApiKeyScopes::ALL), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    private function publishPlan(string $projectId): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: 'pro', name: 'Pro', description: 'Pour gérer votre auto-école', visibility: 'public',
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 3, 'reset' => 'never', 'overage' => 'block', 'threshold_bps' => 8000]],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html($response): string
    {
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }

    private function scalar(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
