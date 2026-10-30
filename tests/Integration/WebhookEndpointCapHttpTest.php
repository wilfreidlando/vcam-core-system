<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #17 / G-12 — the ceiling of **active** webhook endpoints
 * a project may hold.
 *
 * Every active endpoint receives every event of the project: without a
 * ceiling, a project could accumulate an unbounded number of receivers —
 * each one an exfiltration channel. The console's `console-sensitive`
 * throttle bounds the *rate* of the registrations, not their total, so the
 * ceiling is the missing guard-rail.
 *
 * The ceiling is per project, on the model of ADR-0082 §1's ten API keys
 * per project. It is lowered here so the test stays fast and readable —
 * the value itself is a configuration, not a constant of the code.
 */
final class WebhookEndpointCapHttpTest extends TestCase
{
    use RefreshDatabase;

    /** The project the last issued API key belongs to. */
    private string $projectId = '';

    #[Test]
    public function registering_past_the_project_ceiling_is_refused_with_a_422(): void
    {
        config(['webhook.max_active_endpoints_per_project' => 2]);

        $token = $this->issueTestApiKey();
        $projectId = $this->projectId;

        $this->register($token)->assertStatus(201);
        $this->register($token)->assertStatus(201);

        $refused = $this->register($token);

        $refused->assertStatus(422);
        $refused->assertJsonPath('type', 'https://errors.core-system.internal/too-many-webhook-endpoints');
        $refused->assertJsonPath('status', 422);

        // The refused registration created nothing: the ceiling refuses
        // the request, it does not register an endpoint anyway.
        self::assertSame(2, DB::table('webhook_endpoints')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_project_below_the_ceiling_is_unaffected(): void
    {
        config(['webhook.max_active_endpoints_per_project' => 2]);

        $token = $this->issueTestApiKey();

        $this->register($token)->assertStatus(201);
        $this->register($token)->assertStatus(201);

        self::assertSame(2, DB::table('webhook_endpoints')->where('project_id', $this->projectId)->count());
    }

    #[Test]
    public function each_project_has_its_own_ceiling(): void
    {
        config(['webhook.max_active_endpoints_per_project' => 1]);

        $first = $this->issueTestApiKey();
        $firstProject = $this->projectId;

        $second = $this->issueTestApiKey();
        $secondProject = $this->projectId;

        $this->register($first)->assertStatus(201);
        $this->register($second)->assertStatus(201);

        // The first project is at its ceiling; the second one is not
        // affected by it.
        $this->register($first)->assertStatus(422);
        $this->register($second)->assertStatus(422);

        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $firstProject)->count());
        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $secondProject)->count());
    }

    #[Test]
    public function a_deactivated_endpoint_frees_a_slot(): void
    {
        // A deactivated endpoint receives nothing: it must not block the
        // registration of a live one.
        config(['webhook.max_active_endpoints_per_project' => 1]);

        $token = $this->issueTestApiKey();

        $created = $this->register($token);
        $created->assertStatus(201);
        $endpointId = $created->json('id');
        self::assertIsString($endpointId);

        $this->deleteJson('/v1/webhook-endpoints/'.$endpointId, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(204);

        $this->register($token)->assertStatus(201);

        self::assertSame(2, DB::table('webhook_endpoints')->where('project_id', $this->projectId)->count());
        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $this->projectId)->where('active', true)->count());
    }

    /**
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function register(string $token): TestResponse
    {
        return $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://example.com/hooks/inbound',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * Creates an organization, a project and an API key scoped to the
     * `webhooks:write` scope; returns the key's plaintext token.
     */
    private function issueTestApiKey(string $scope = 'webhooks:write'): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Test Org'),
            actor: 'service:test',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Test Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;
        $this->projectId = $project->id;

        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, [$scope]),
            actor: 'service:test',
            correlationId: null,
        );

        return $issued->plaintextToken;
    }
}
