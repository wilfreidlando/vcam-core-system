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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADR-0063 — `POST /v1/webhook-endpoints/{id}/test`, and the signature
 * verification snippets of docs/integration/webhooks.md run against a
 * REAL signed request produced by the Core: the documentation cannot drift
 * from what the Core sends. Node/Python snippets run when the binary is
 * available on the test host.
 */
final class WebhookTestAndSignatureExamplesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_test_endpoint_sends_a_signed_test_event_that_every_documented_verifier_accepts(): void
    {
        Http::preventStrayRequests();
        Http::fake(['example.com/hooks/test' => Http::response('ok', 200)]);

        [$token] = $this->project();
        $created = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/test', 'events' => ['*']], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);
        $endpointId = $created->json('id');
        $secret = $created->json('secret');
        self::assertIsString($endpointId);
        self::assertIsString($secret);

        $result = $this->postJson("/v1/webhook-endpoints/{$endpointId}/test", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('response_status', 200);

        $captured = null;
        Http::assertSent(function (Request $request) use (&$captured): bool {
            $captured = $request;

            return $request->url() === 'https://example.com/hooks/test';
        });
        self::assertInstanceOf(Request::class, $captured);

        $body = $captured->body();
        $timestamp = $captured->header('X-Timestamp')[0] ?? '';
        $signature = $captured->header('X-Signature')[0] ?? '';
        self::assertIsString($timestamp);
        self::assertIsString($signature);
        self::assertSame($result->json('event_id'), $captured->header('X-Event-Id')[0] ?? null);
        self::assertSame('webhook.test', $captured->header('X-Event-Type')[0] ?? null);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('webhook.test', $decoded['type'] ?? null);

        $verifiers = [
            'php' => [PHP_BINARY, base_path('docs/integration/examples/verify-signature.php')],
            'node' => ['node', base_path('docs/integration/examples/verify-signature.js')],
            'python' => ['python3', base_path('docs/integration/examples/verify_signature.py')],
        ];

        $ran = 0;
        foreach ($verifiers as $name => $command) {
            if ($name !== 'php' && ! $this->binaryExists($command[0])) {
                continue;
            }
            $ran++;

            self::assertSame('valid', $this->verify($command, $secret, $timestamp, $signature, $body), "{$name} verifier rejected a genuine signature");
            self::assertSame('invalid', $this->verify($command, $secret, $timestamp, $signature, $body.' '), "{$name} verifier accepted a tampered body");
            self::assertSame('invalid', $this->verify($command, 'wrong-secret', $timestamp, $signature, $body), "{$name} verifier accepted a wrong secret");
            self::assertSame('invalid', $this->verify($command, $secret, (string) ((int) $timestamp - 3600), $signature, $body), "{$name} verifier accepted a stale timestamp");
        }
        self::assertGreaterThanOrEqual(1, $ran);
    }

    #[Test]
    public function an_unreachable_receiver_is_reported_not_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['example.com/hooks/down' => Http::response('nope', 500)]);

        [$token] = $this->project();
        $endpointId = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/down', 'events' => ['*']], ['Authorization' => "Bearer {$token}"])
            ->json('id');
        self::assertIsString($endpointId);

        $this->postJson("/v1/webhook-endpoints/{$endpointId}/test", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('response_status', 500)
            ->assertJsonPath('response_body', 'nope');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    #[Test]
    public function every_emitted_event_type_can_be_subscribed_to_and_a_phantom_one_cannot(): void
    {
        [$token] = $this->project();

        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/all', 'events' => \Core\Webhook\Contracts\WebhookEventTypes::ALL], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);
        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/x', 'events' => ['notification.status_changed']], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);
    }

    #[Test]
    public function another_projects_endpoint_cannot_be_tested(): void
    {
        [$tokenA] = $this->project();
        [$tokenB] = $this->project();
        $endpointId = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/a', 'events' => ['*']], ['Authorization' => "Bearer {$tokenA}"])
            ->json('id');
        self::assertIsString($endpointId);

        $this->postJson("/v1/webhook-endpoints/{$endpointId}/test", [], ['Authorization' => "Bearer {$tokenB}"])->assertNotFound();
    }

    /**
     * @param list<string> $command
     */
    private function verify(array $command, string $secret, string $timestamp, string $signature, string $body): string
    {
        $process = new Process($command, null, [
            'WEBHOOK_SECRET' => $secret,
            'X_TIMESTAMP' => $timestamp,
            'X_SIGNATURE' => $signature,
        ], $body, 30);
        $process->run();

        return trim($process->getOutput());
    }

    private function binaryExists(string $binary): bool
    {
        $process = new Process(['sh', '-c', 'command -v '.escapeshellarg($binary)]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Project'), actor: 'service:test', correlationId: null)->project;

        return [app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, Environment::Sandbox, ['*'], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        )->plaintextToken, $project->id];
    }
}
