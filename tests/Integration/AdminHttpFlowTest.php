<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\CreateOrganizationCommand;
use Core\Identity\Application\Dto\CreateProjectCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Application\UseCase\CreateOrganization;
use Core\Identity\Application\UseCase\CreateProject;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Money\Money;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
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

    #[Test]
    public function an_operator_with_projects_read_lists_projects_optionally_scoped_to_an_organization(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::ReadOnlyAuditor);

        $orgA = app(CreateOrganization::class)(new CreateOrganizationCommand('Org A'), actor: 'service:test', correlationId: null);
        $orgB = app(CreateOrganization::class)(new CreateOrganizationCommand('Org B'), actor: 'service:test', correlationId: null);
        app(CreateProject::class)(new CreateProjectCommand($orgA->id, 'Project A1'), actor: 'service:test', correlationId: null);
        app(CreateProject::class)(new CreateProjectCommand($orgB->id, 'Project B1'), actor: 'service:test', correlationId: null);

        $this->getJson('/v1/admin/projects', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/v1/admin/projects?organization_id={$orgA->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Project A1');
    }

    #[Test]
    public function an_operator_lists_a_projects_payments_wallets_and_notifications(): void
    {
        $organization = app(CreateOrganization::class)(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)(new CreateProjectCommand($organization->id, 'Project'), actor: 'service:test', correlationId: null);
        $partyId = (string) Str::uuid();

        app(PaymentRepository::class)->create(new Payment(
            id: (string) Str::uuid(),
            projectId: $project->id,
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: (string) Str::uuid(),
            providerTransactionRef: (string) Str::uuid(),
            amount: 5_000,
            currency: 'XAF',
            status: PaymentStatus::Succeeded,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
        ));

        $walletId = (string) Str::uuid();
        app(WalletRepository::class)->save(new Wallet($walletId, $project->id, $partyId, 'XAF', 'active'));
        app(WalletBalanceRepository::class)->initialize($walletId, 'XAF');
        app(WalletBalanceRepository::class)->save(new WalletBalance($walletId, Money::ofMinor(2_500, 'XAF'), Money::zero('XAF'), 1));

        app(NotificationRepository::class)->create(new Notification(
            id: (string) Str::uuid(),
            projectId: $project->id,
            channel: 'whatsapp',
            toPartyRef: $partyId,
            contentType: 'text',
            idempotencyKey: (string) Str::uuid(),
            status: NotificationStatus::Delivered,
            providerCode: 'whatsapp-waha',
            providerMessageId: null,
            errorMessage: null,
        ));

        $financeToken = $this->authenticatedOperatorToken(OperatorRole::FinanceOperator);
        $supportToken = $this->authenticatedOperatorToken(OperatorRole::SupportOperator);

        $this->getJson("/v1/admin/projects/{$project->id}/payments", ['Authorization' => "Bearer {$financeToken}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount', 5_000);

        $this->getJson("/v1/admin/projects/{$project->id}/wallets", ['Authorization' => "Bearer {$financeToken}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.available_minor', 2_500);

        $this->getJson("/v1/admin/projects/{$project->id}/notifications", ['Authorization' => "Bearer {$supportToken}"])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'delivered');

        // Finance has no notifications:read, Support has no wallets:read.
        $this->getJson("/v1/admin/projects/{$project->id}/notifications", ['Authorization' => "Bearer {$financeToken}"])
            ->assertStatus(403);
        $this->getJson("/v1/admin/projects/{$project->id}/wallets", ['Authorization' => "Bearer {$supportToken}"])
            ->assertStatus(403);
    }

    #[Test]
    public function a_platform_admin_creates_lists_and_revokes_an_operator(): void
    {
        // ADR-0050: operator self-service — only PlatformAdmin's `*`
        // wildcard carries `operators:read`/`operators:write`.
        $token = $this->authenticatedOperatorToken(OperatorRole::PlatformAdmin);
        $email = Str::uuid().'@example.com';

        $create = $this->postJson('/v1/admin/operators', [
            'email' => $email,
            'name' => 'New Operator',
            'role' => OperatorRole::SupportOperator->value,
        ], ['Authorization' => "Bearer {$token}"]);
        $create->assertStatus(201);
        $create->assertJsonStructure(['id', 'plaintext_password']);
        $operatorId = $create->json('id');
        self::assertIsString($operatorId);

        $list = $this->getJson('/v1/admin/operators', ['Authorization' => "Bearer {$token}"]);
        $list->assertOk();
        $list->assertJsonFragment(['id' => $operatorId, 'email' => $email, 'revoked' => false]);

        $this->postJson("/v1/admin/operators/{$operatorId}/revoke", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(204);

        // Idempotent — revoking again succeeds without effect.
        $this->postJson("/v1/admin/operators/{$operatorId}/revoke", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(204);

        $this->getJson('/v1/admin/operators', ['Authorization' => "Bearer {$token}"])
            ->assertJsonFragment(['id' => $operatorId, 'revoked' => true]);
    }

    #[Test]
    public function creating_an_operator_with_an_unknown_role_is_rejected_with_a_clean_422(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::PlatformAdmin);

        $this->postJson('/v1/admin/operators', [
            'email' => Str::uuid().'@example.com',
            'name' => 'Bad Role',
            'role' => 'not_a_real_role',
        ], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function an_operator_without_operators_permission_is_forbidden_from_managing_operators(): void
    {
        $token = $this->authenticatedOperatorToken(OperatorRole::ReadOnlyAuditor);

        $this->getJson('/v1/admin/operators', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);

        $this->postJson('/v1/admin/operators', [
            'email' => Str::uuid().'@example.com',
            'name' => 'Nope',
            'role' => OperatorRole::SupportOperator->value,
        ], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    #[Test]
    public function a_malformed_project_or_organization_id_is_rejected_with_a_clean_422_not_a_500(): void
    {
        // Review finding, Lot 6 follow-up: a non-UUID id used to reach the
        // Postgres `uuid` column unvalidated and crash with an uncaught
        // QueryException. Covers one path param and one query param —
        // the other three listing endpoints share the identical fix.
        $token = $this->authenticatedOperatorToken(OperatorRole::PlatformAdmin);

        $this->getJson('/v1/admin/projects/not-a-uuid/payments', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->getJson('/v1/admin/projects?organization_id=not-a-uuid', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    private function authenticatedOperatorToken(OperatorRole $role): string
    {
        $created = app(CreateOperator::class)->create(
            new CreateOperatorCommand(Str::uuid().'@example.com', 'Ops', $role->value),
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
