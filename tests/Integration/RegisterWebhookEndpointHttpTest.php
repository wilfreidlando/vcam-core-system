<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lives under `Tests\Integration` (app-level), not `Core\Webhook\Tests\...`:
 * this scenario deliberately orchestrates Identity fixtures directly,
 * which `webhook`'s own test suite cannot do (G3c).
 *
 * Security review finding: `POST /v1/webhook-endpoints` used to accept
 * any http(s) URL, including internal/private targets — SSRF, and a
 * persistent one (retried, DLQ-replayed). `SafeOutboundUrlRule` closes
 * this at the HTTP boundary.
 */
final class RegisterWebhookEndpointHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function an_unsafe_destination_url_is_rejected(string $url): void
    {
        $token = $this->issueTestApiKey();

        $response = $this->postJson('/v1/webhook-endpoints', [
            'url' => $url,
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/hook'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private range' => ['http://10.0.0.5/hook'],
            'localhost' => ['http://localhost/hook'],
        ];
    }

    #[Test]
    public function a_publicly_routable_url_is_accepted(): void
    {
        $token = $this->issueTestApiKey();

        $response = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://example.com/hooks/inbound',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'url', 'events', 'secret']);
    }

    #[Test]
    public function a_plain_http_url_is_rejected(): void
    {
        // Audit 2026-10-03 §2.3 — payment statuses and amounts never travel
        // in clear text; http:// is only allowed with an explicit local opt-in.
        $token = $this->issueTestApiKey();

        $response = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'http://example.com/hooks/inbound',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    #[Test]
    public function the_documented_example_url_is_itself_accepted(): void
    {
        // User-reported: the `@example` on `RegisterWebhookEndpointRequest`'s
        // `url` rule used to be `https://my-app.example.com/webhooks/core`
        // — a subdomain of the reserved example.com that has no DNS record
        // at all, so SafeOutboundUrlRule's real resolution check rejected
        // it. An integrator copy-pasting the doc's own example got a 422.
        // Pinning the literal value here (not just "some example.com URL",
        // already covered above) means a future edit to the docblock that
        // reintroduces an unresolvable host fails this test, not a
        // stranger's production integration.
        $token = $this->issueTestApiKey();

        $response = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://example.com/webhooks/core',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
    }

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

        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, Environment::Sandbox, [$scope]),
            actor: 'service:test',
            correlationId: null,
        );

        return $issued->plaintextToken;
    }
}
