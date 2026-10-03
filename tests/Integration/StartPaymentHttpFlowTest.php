<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lives under `Tests\Integration` (app-level), not `Core\Payment\Tests\...`:
 * this scenario deliberately orchestrates Identity fixtures directly,
 * which `payment`'s own test suite cannot do (G3c).
 *
 * End-to-end coverage for `POST /v1/payments/paylink` and
 * `POST /v1/payments/payin` (user request — two explicit entry points
 * instead of one generic endpoint branching on a `flow` field), plus the
 * per-Project MyCoolPay application requirement and the optional
 * `callback_url`/`callback_secret` pair (security review finding,
 * user-directed expansion) — real HTTP, real Sanctum-adjacent ApiKey
 * auth, `Http::fake()` only at the MyCoolPay boundary.
 */
final class StartPaymentHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function starting_a_paylink_payment_returns_a_payment_url(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-flow-1');

        Http::fake([
            'my-coolpay.com/api/pub-flow-1/paylink' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-paylink-1',
                'payment_url' => 'https://my-coolpay.com/pay/abc',
            ], 201),
        ]);

        $response = $this->postJson('/v1/payments/paylink', [
            'channel' => 'mtn_momo',
            'amount' => 1_000,
            'currency' => 'XAF',
            'reason' => 'Order #1',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('payment_url', 'https://my-coolpay.com/pay/abc');
        $response->assertJsonMissingPath('callback_secret');
    }

    #[Test]
    public function starting_a_payin_payment_with_a_callback_url_returns_a_one_time_callback_secret(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-flow-2');

        Http::fake([
            'my-coolpay.com/api/pub-flow-2/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-payin-1',
                'action' => 'PENDING',
            ], 201),
        ]);

        $response = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 1_000,
            'currency' => 'XAF',
            'reason' => 'Order #2',
            'callback_url' => 'https://example.com/callbacks/payments',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
        $secret = $response->json('callback_secret');
        self::assertIsString($secret);
        self::assertNotSame('', $secret);
    }

    #[Test]
    public function an_unsafe_callback_url_is_rejected(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-flow-3');

        $response = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 1_000,
            'currency' => 'XAF',
            'reason' => 'Order #3',
            'callback_url' => 'http://169.254.169.254/latest/meta-data/',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function the_documented_callback_url_example_is_itself_accepted(): void
    {
        // User-reported: the `@example` on `StartPaymentRequest`'s
        // `callback_url` rule used to be
        // `https://my-app.example.com/webhooks/core` — a subdomain of the
        // reserved example.com that has no DNS record at all, so
        // SafeOutboundUrlRule's real resolution check rejected it. An
        // integrator copy-pasting the doc's own example got a 422.
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-flow-doc-example');

        Http::fake([
            'my-coolpay.com/api/pub-flow-doc-example/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-doc-example',
                'action' => 'PENDING',
            ], 201),
        ]);

        $response = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 1_000,
            'currency' => 'XAF',
            'reason' => 'Order #doc-example',
            'callback_url' => 'https://example.com/webhooks/core',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function starting_a_live_payment_without_a_registered_application_fails_cleanly(): void
    {
        // Live only: a sandbox payment without a sandbox application goes
        // to the built-in simulator instead (ADR-0060, tested below).
        [$token] = $this->authenticatedProject(environment: Environment::Live);
        // Deliberately no registerMyCoolPayApplication() call — this
        // Project has no MyCoolPay application registered yet.

        $response = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 1_000,
            'currency' => 'XAF',
            'reason' => 'Order #4',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        // ProviderApplicationNotFound is mapped to a clean 422 in
        // ExceptionProblemMapping (B3 fix — was 500 with stack trace
        // before, see ADR-0033 §3 + ADR-0051 audit). Asserting the
        // 422 surfaces the bug fix; the exact body shape is covered
        // by tests/Integration/Http/ExceptionProblemMappingB3FixTest.
        $response->assertStatus(422);
    }

    #[Test]
    public function a_sandbox_payment_without_a_sandbox_application_is_simulated_end_to_end(): void
    {
        // ADR-0060 / D2: no provider call, no real money — the integrator
        // drives the outcome and receives the same notifications as live.
        Http::preventStrayRequests();
        Http::fake(['example.com/hooks/sandbox' => Http::response(['ok' => true], 200)]);

        [$token, $projectId] = $this->authenticatedProject('*');

        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/sandbox', 'events' => ['payment.status_changed']], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);

        $start = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 750,
            'currency' => 'XAF',
            'reason' => 'Sandbox order',
        ], ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]);

        $start->assertStatus(201)->assertJsonPath('status', 'pending')->assertJsonPath('is_final', false);
        $hint = $start->json('action_hint');
        self::assertIsString($hint);
        self::assertStringContainsString('SANDBOX', $hint);
        $paymentId = $start->json('id');
        self::assertIsString($paymentId);

        $this->postJson("/v1/sandbox/payments/{$paymentId}/simulate", ['status' => 'succeeded'], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('status', 'succeeded');

        $this->getJson("/v1/payments/{$paymentId}", ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('status', 'succeeded');

        // A final payment can no longer be simulated.
        $this->postJson("/v1/sandbox/payments/{$paymentId}/simulate", ['status' => 'failed'], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(409);

        // The sandbox webhook endpoint is notified like in live.
        app(\Core\Events\Contracts\RelayOutboxEvents::class)->relay(100);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://example.com/hooks/sandbox'
            && str_contains($request->body(), '"status":"succeeded"'));
    }

    #[Test]
    public function a_live_key_cannot_simulate_nor_read_a_sandbox_payment(): void
    {
        [$sandboxToken, $projectId] = $this->authenticatedProject('*');
        $paymentId = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo', 'amount' => 100, 'currency' => 'XAF', 'reason' => 'x',
        ], ['Authorization' => "Bearer {$sandboxToken}", 'Idempotency-Key' => (string) Str::uuid()])->json('id');
        self::assertIsString($paymentId);

        $liveToken = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($projectId, Environment::Live, ['*'], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        )->plaintextToken;

        $this->getJson("/v1/payments/{$paymentId}", ['Authorization' => "Bearer {$liveToken}"])->assertNotFound();
        $this->postJson("/v1/sandbox/payments/{$paymentId}/simulate", ['status' => 'succeeded'], [
            'Authorization' => "Bearer {$liveToken}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    /**
     * @return array{0: string, 1: string} [bearer token, project id]
     */
    private function authenticatedProject(string $scope = 'payments:write', Environment $environment = Environment::Sandbox): array
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
            new IssueApiKeyCommand($project->id, $environment, [$scope], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        );

        return [$issued->plaintextToken, $project->id];
    }

    private function registerMyCoolPayApplication(string $projectId, string $publicKey): void
    {
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: 'my-coolpay',
            publicKey: $publicKey,
            privateKey: 'test-private-key',
            // Sandbox key + sandbox application: the call goes to the
            // provider's sandbox, not the built-in simulator (ADR-0060).
            environment: Environment::Sandbox,
        ));
    }
}
