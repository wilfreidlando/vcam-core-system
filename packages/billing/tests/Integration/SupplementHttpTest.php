<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0117 §4 over HTTP: the wire contract of the purchase of a
 * supplement — the `Idempotency-Key` it demands, the status codes, the
 * RFC 7807 bodies, the scopes and the project isolation.
 */
final class SupplementHttpTest extends TestCase
{
    use RefreshDatabase;

    private const METER = 'whatsapp_messages';

    #[Test]
    public function a_purchase_answers_the_supplement_and_the_invoice_that_carries_it(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write', 'subscriptions:read']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonPath('supplement.status', 'pending')
            ->assertJsonPath('supplement.meter', self::METER)
            ->assertJsonPath('supplement.quantity', 50)
            ->assertJsonPath('supplement.total_minor', 250000)
            ->assertJsonPath('supplement.currency', 'XAF')
            ->assertJsonPath('invoice.status', 'open');
    }

    #[Test]
    public function the_purchase_demands_an_idempotency_key(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token))
            ->assertStatus(400)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'idempotency-key-required'));

        self::assertSame(0, DB::table('subscription_supplements')->count());
    }

    #[Test]
    public function a_replayed_purchase_buys_once(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');
        $key = (string) Str::uuid();

        $first = $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => $key])
            ->assertStatus(201);

        // The same key, the same body: the answer is replayed, and
        // nothing is bought twice.
        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => $key])
            ->assertStatus(201)
            ->assertJsonPath('supplement.id', $first->json('supplement.id'));

        self::assertSame(1, DB::table('subscription_supplements')->count());
        self::assertSame(1, DB::table('invoices')->count());
    }

    #[Test]
    public function a_key_reused_for_another_body_is_a_conflict(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');
        $key = (string) Str::uuid();

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => $key])
            ->assertStatus(201);

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", ['meter' => self::METER, 'quantity' => 10, 'unit_price_minor' => 5_000], $this->auth($token) + ['Idempotency-Key' => $key])
            ->assertStatus(409);
    }

    #[Test]
    public function the_scopes_gate_the_two_routes(): void
    {
        [$token, $projectId] = $this->project(['customers:read']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403);
        $this->getJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->auth($token))
            ->assertStatus(403);
    }

    #[Test]
    public function a_meter_the_plan_does_not_carry_is_refused_with_a_problem_document(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", ['meter' => 'storage_mb', 'quantity' => 50, 'unit_price_minor' => 5_000], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function the_body_is_validated(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", ['meter' => 'Bad Meter!', 'quantity' => 0], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    #[Test]
    public function the_listing_answers_the_purchases_of_the_contract(): void
    {
        [$token, $projectId] = $this->project(['subscriptions:write', 'subscriptions:read']);
        $subscriptionId = $this->subscribe($projectId, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201);

        $this->getJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.quantity', 50)
            ->assertJsonPath('data.0.customer_ref', 'AE0001');
    }

    #[Test]
    public function a_project_cannot_buy_on_the_contract_of_another(): void
    {
        [$tokenA, $projectA] = $this->project(['subscriptions:write']);
        [$tokenB] = $this->project(['subscriptions:write']);
        $subscriptionId = $this->subscribe($projectA, 'AE0001');

        $this->postJson("/v1/subscriptions/{$subscriptionId}/supplements", $this->body(), $this->auth($tokenB) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(404);

        self::assertSame(0, DB::table('subscription_supplements')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return ['meter' => self::METER, 'quantity' => 50, 'unit_price_minor' => 5_000];
    }

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

    private function subscribe(string $projectId, string $ref): string
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Auto-école', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public',
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 5_000, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 14, graceDays: 7, graceAccess: true,
            features: [
                'api_access' => ['type' => 'boolean', 'enabled' => true],
                'messages' => ['type' => 'limit', 'meter' => self::METER, 'limit' => 100, 'reset' => 'period', 'overage' => 'block'],
            ],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $ref, planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-'.$ref, actor: 'test', correlationId: null,
        ));

        $subscriptionId = DB::table('subscriptions')->where('project_id', $projectId)->value('id');

        self::assertIsString($subscriptionId);

        return $subscriptionId;
    }
}
