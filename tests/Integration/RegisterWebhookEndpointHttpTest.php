<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Application\Dto\IssueApiKeyCommand;
use Core\Identity\Application\UseCase\IssueApiKey;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
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
        );

        $issued = app(IssueApiKey::class)(
            new IssueApiKeyCommand($project->id, Environment::Sandbox, [$scope]),
            actor: 'service:test',
            correlationId: null,
        );

        return $issued->plaintextToken;
    }
}
