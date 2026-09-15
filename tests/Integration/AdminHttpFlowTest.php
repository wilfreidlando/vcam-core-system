<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\CreateOperatorCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Application\UseCase\CreateOperator;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * ADR-0044: the four operator-console capabilities delivered this round —
 * audit search, webhook DLQ list + replay, on-demand reconciliation —
 * exercised end to end via real HTTP, real permission checks, and a real
 * Sanctum token (not direct use-case calls — see this project's own
 * testing convention after the Lot 5 review).
 *
 * Lives under `Tests\Integration` (app-level), not `Core\Admin\Tests\...`:
 * this scenario deliberately orchestrates Identity, Webhook and Audit test
 * fixtures directly, which `admin`'s own test suite cannot do (G3c —
 * `admin` reaches every other module only through Contracts\, even in its
 * own tests).
 */
final class AdminHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_operator_with_audit_read_can_search_the_audit_log(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::ReadOnlyAuditor);

        app(AuditLogger::class)->record(new AuditEntry(
            actor: 'service:test',
            action: 'test.something_happened',
            resource: 'project:x',
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: new DateTimeImmutable,
        ));

        $this->getJson('/v1/admin/audit-log?action=test.something_happened', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'test.something_happened');
    }

    #[Test]
    public function an_operator_without_webhook_permission_is_forbidden(): void
    {
        // No role in this slice lacks audit:read by design (every role
        // needs to see what it did) — Provider Manager's charter is the
        // narrowest, so it stands in for "no webhook permission" instead.
        $token = $this->authenticatedOperatorToken(OperatorRole::ProviderManager);

        $this->getJson('/v1/admin/webhook-deliveries/dead', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function an_operator_with_webhooks_permissions_lists_and_replays_a_dead_delivery(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::SupportOperator);

        $projectId = (string) Str::uuid();
        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hook', ['*']),
        );

        $deadDeliveryId = (string) Str::uuid();
        app(WebhookDeliveryRepository::class)->create(new WebhookDelivery(
            id: $deadDeliveryId,
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'payment.status_changed',
            payload: ['foo' => 'bar'],
            status: WebhookDeliveryStatus::Dead,
            attempts: 8,
            nextRetryAt: null,
            lastAttemptAt: new DateTimeImmutable,
            lastError: 'connection timed out',
            deliveredAt: null,
        ));

        $this->getJson('/v1/admin/webhook-deliveries/dead', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $deadDeliveryId)
            ->assertJsonPath('data.0.last_error', 'connection timed out');

        // No Idempotency-Key header sent — this route must not require one
        // (RequireIdempotencyKey is wired to ProjectContext, never bound on
        // an operator-authenticated request).
        $replayed = $this->postJson("/v1/admin/webhook-deliveries/{$deadDeliveryId}/replay", [], ['Authorization' => "Bearer {$token}"])
            ->assertCreated()
            ->json();

        // The replay creates a fresh delivery row rather than mutating the
        // dead one (ADR-0040 §7 — original attempt history stays intact
        // for audit), so the dead list still lists the original afterwards.
        self::assertIsArray($replayed);
        self::assertNotSame($deadDeliveryId, $replayed['id']);

        $this->getJson('/v1/admin/webhook-deliveries/dead', ['Authorization' => "Bearer {$token}"])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $deadDeliveryId);
    }

    #[Test]
    public function an_operator_with_reconcile_permission_can_trigger_a_reconciliation_run_with_nothing_stuck(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::FinanceOperator);

        // No stuck payments seeded — proves the endpoint, permission gate
        // and response shape without needing a fake HTTP provider call.
        $this->postJson('/v1/admin/payment-reconciliation-runs', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['checked' => 0, 'updated' => 0, 'errors' => 0]);
    }

    #[Test]
    public function an_operator_without_reconcile_permission_is_forbidden(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::ReadOnlyAuditor);

        $this->postJson('/v1/admin/payment-reconciliation-runs', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    private function authenticatedOperatorToken(OperatorRole $role): string
    {
        $created = app(CreateOperator::class)(
            new CreateOperatorCommand(Str::uuid().'@example.com', 'Ops', $role),
            actor: 'service:test',
            correlationId: null,
        );

        $operator = app(OperatorRepository::class)->find($created->operatorId);
        assert($operator instanceof Operator);

        $login = app(LoginOperator::class)(new LoginOperatorCommand($operator->email, $created->plaintextPassword));

        $enrollment = app(EnrollOperatorMfa::class)(new EnrollOperatorMfaCommand($login->challengeToken));
        $code = (new Google2FA)->getCurrentOtp($enrollment->secret);

        $session = app(ConfirmOperatorMfaEnrollment::class)(
            new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, $code),
        );

        return $session->accessToken;
    }
}
