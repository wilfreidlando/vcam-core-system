<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Application\UseCase\SnapshotMetricsUseCase;
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
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0080 §2, §4 — the nightly snapshot, the curves and the readiness checklist.
 */
final class ProjectIndicatorsConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_nightly_pass_is_replayable_and_the_console_draws_the_curve_from_it(): void
    {
        $projectId = $this->projectWithOneSubscriber();

        $first = app(SnapshotMetricsUseCase::class)->snapshot();
        $again = app(SnapshotMetricsUseCase::class)->snapshot();

        self::assertSame(1, $first['projects']);
        self::assertSame($first, $again);
        self::assertSame(1, DB::table('billing_daily_metrics')->where('project_id', $projectId)->count(), 'Replayed the same day, the pass overwrites the row.');

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $page = $this->get("/admin/projects/{$projectId}/indicators?days=30")->assertOk();
        $page->assertSee('Revenu récurrent mensuel')->assertSee('<polyline', false)->assertSee('Créances par ancienneté');
        // The project has a published plan and nothing else wired yet.
        $page->assertSee('Une offre publiée')->assertSee('Un webhook actif');
    }

    #[Test]
    public function a_finance_role_reads_usage_and_a_support_role_never_reads_what_the_project_earns(): void
    {
        self::assertTrue(OperatorRole::PlatformAdmin->hasPermission('invoices:write'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Finance->hasPermission('usage:read'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Finance->hasPermission('plans:read'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Support->hasPermission('usage:read'));
        self::assertFalse(\Core\Identity\Domain\Model\OrganizationRole::Support->hasPermission('invoices:write'));

        $projectId = $this->projectWithOneSubscriber();
        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);
        $this->get("/admin/projects/{$projectId}/indicators")->assertStatus(403);
    }

    #[Test]
    public function a_stat_tile_shows_an_amount_with_its_currency_as_written_not_as_a_number(): void
    {
        // Found by the browser session: « 85 000 XAF » was cast to a number and shown as « 85 ».
        $html = \Illuminate\Support\Facades\Blade::render('<x-admin.stat label="Encaissé" text="85 000 XAF" /><x-admin.stat label="Actifs" text="2 / 3" /><x-admin.stat label="Total" :value="12345" />');

        self::assertStringContainsString('85 000 XAF', $html);
        self::assertStringContainsString('2 / 3', $html);
        self::assertStringContainsString('12,345', $html);
    }

    private function projectWithOneSubscriber(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;

        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true, features: [], actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Client', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0001', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-1', actor: 'test', correlationId: null,
        ));

        return $projectId;
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }
}
