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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0074 §4 over HTTP: the wire contract of the quota authority —
 * status codes, RFC 7807 bodies, scopes and project isolation.
 */
final class UsageHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_project_spends_its_plan_then_is_refused_with_a_problem_document(): void
    {
        [$token, $projectId] = $this->project(['usage:write', 'usage:read']);
        $this->subscribe($projectId, 'AE0001', limit: 2);

        $body = fn (string $key): array => [
            'customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => $key,
        ];

        $this->postJson('/v1/usage', $body('s1'), $this->auth($token))
            ->assertStatus(201)
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('used', 1)
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('remaining', 1);

        // The same key again: 200, nothing counted twice.
        $this->postJson('/v1/usage', $body('s1'), $this->auth($token))
            ->assertStatus(200)
            ->assertJsonPath('status', 'replayed')
            ->assertJsonPath('used', 1);

        $this->postJson('/v1/usage', $body('s2'), $this->auth($token))->assertStatus(201)->assertJsonPath('remaining', 0);

        $this->postJson('/v1/usage', $body('s3'), $this->auth($token))
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'quota-exceeded'))
            ->assertJsonPath('used', 2)
            ->assertJsonPath('limit', 2);

        $entitlements = $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($token))
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('plan_code', 'pro');

        // jsonb does not keep the order of the keys: find the feature by name.
        $students = collect((array) $entitlements->json('entitlements'))->firstWhere('key', 'students');

        self::assertIsArray($students);
        self::assertSame(2, $students['used']);
        self::assertSame(0, $students['remaining']);
    }

    #[Test]
    public function the_scopes_gate_both_routes(): void
    {
        [$token] = $this->project(['customers:read']);

        $this->postJson('/v1/usage', ['meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'k'], $this->auth($token))
            ->assertStatus(403);
        $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($token))
            ->assertStatus(403);
    }

    #[Test]
    public function the_body_is_validated(): void
    {
        [$token] = $this->project(['usage:write']);

        $this->postJson('/v1/usage', ['meter' => 'Bad Meter!', 'quantity' => 0], $this->auth($token))
            ->assertStatus(422);
    }

    #[Test]
    public function a_project_cannot_read_or_spend_the_customer_of_another(): void
    {
        [$tokenA, $projectA] = $this->project(['usage:write', 'usage:read']);
        [$tokenB] = $this->project(['usage:write', 'usage:read']);
        $this->subscribe($projectA, 'AE0001', limit: 2);

        $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($tokenB))->assertStatus(404);
        $this->postJson('/v1/usage', [
            'customer_ref' => 'AE0001', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'x',
        ], $this->auth($tokenB))->assertStatus(404);

        $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($tokenA))->assertOk();
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

    private function subscribe(string $projectId, string $ref, int $limit): void
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
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: [
                'api_access' => ['type' => 'boolean', 'enabled' => true],
                'students' => ['type' => 'limit', 'meter' => 'students', 'limit' => $limit, 'reset' => 'never', 'overage' => 'block'],
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
    }
}
