<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Application\UseCase\CreateOrganization;
use Core\Identity\Application\UseCase\CreateProject;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\RevokeApiKey;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The end-to-end path this whole Lot 1 pass exists to prove: an application
 * is created, a key is issued, an authenticated call is correlated and
 * audited, and revocation takes effect immediately — against a real
 * PostgreSQL database and the real HTTP kernel, not fakes.
 */
final class IdentityHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authenticates_whoami_with_a_freshly_issued_key_and_echoes_the_correlation_id(): void
    {
        $token = $this->issueTestApiKey();

        $this->withHeader('Correlation-Id', 'test-correlation-1')
            ->getJson('/v1/whoami', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertHeader('Correlation-Id', 'test-correlation-1')
            ->assertJson(['scopes' => ['payments:write']])
            ->assertJsonMissingPath('environment');
    }

    #[Test]
    public function it_rejects_a_request_with_no_authorization_header_as_rfc7807(): void
    {
        $this->getJson('/v1/whoami')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'correlation_id']);
    }

    #[Test]
    public function it_rejects_an_unknown_key(): void
    {
        $this->getJson('/v1/whoami', ['Authorization' => 'Bearer pk_unknown0.whatever'])
            ->assertStatus(401);
    }

    #[Test]
    public function it_generates_a_correlation_id_when_the_caller_does_not_supply_one(): void
    {
        $token = $this->issueTestApiKey();

        $response = $this->getJson('/v1/whoami', ['Authorization' => "Bearer {$token}"])->assertOk();

        self::assertNotNull($response->headers->get('Correlation-Id'));
    }

    #[Test]
    public function it_stops_authenticating_the_instant_a_key_is_revoked(): void
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
        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, [], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        );

        $this->getJson('/v1/whoami', ['Authorization' => "Bearer {$issued->plaintextToken}"])->assertOk();

        app(RevokeApiKey::class)->revoke(
            new RevokeApiKeyCommand($issued->apiKeyId),
            actor: 'service:test',
            correlationId: null,
        );

        $this->getJson('/v1/whoami', ['Authorization' => "Bearer {$issued->plaintextToken}"])->assertStatus(401);
    }

    #[Test]
    public function it_records_every_administrative_action_in_the_audit_log_without_leaking_the_secret(): void
    {
        $token = $this->issueTestApiKey();

        $rows = DB::table('audit_log')->orderBy('id')->get();

        // organization.created, project.created, api_key.issued
        // (auto-provisioned), then the explicit-scope test key
        // `issueTestApiKey` REGENERATES it (ADR-0066 — one key per
        // project): the old key's deletion (api_key.replaced) and the new
        // one (api_key.regenerated).
        self::assertCount(5, $rows);
        self::assertSame(
            ['organization.created', 'project.created', 'api_key.issued', 'api_key.replaced', 'api_key.regenerated'],
            $rows->pluck('action')->all(),
        );

        $secret = explode('.', $token)[1];

        foreach ($rows as $row) {
            self::assertIsString($row->after);
            self::assertStringNotContainsString($secret, $row->after);
        }
    }

    private function issueTestApiKey(string $scope = 'payments:write'): string
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

        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, [$scope], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        );

        return $issued->plaintextToken;
    }
}
