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
    public function starting_a_payment_without_a_registered_application_fails_cleanly(): void
    {
        [$token] = $this->authenticatedProject();
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

    /**
     * @return array{0: string, 1: string} [bearer token, project id]
     */
    private function authenticatedProject(string $scope = 'payments:write'): array
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

        return [$issued->plaintextToken, $project->id];
    }

    private function registerMyCoolPayApplication(string $projectId, string $publicKey): void
    {
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: 'my-coolpay',
            publicKey: $publicKey,
            privateKey: 'test-private-key',
        ));
    }
}
