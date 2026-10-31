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
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function a_support_role_reads_the_onboarding_of_the_project_but_never_what_it_earns(): void
    {
        self::assertTrue(OperatorRole::PlatformAdmin->hasPermission('invoices:write'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Finance->hasPermission('usage:read'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Finance->hasPermission('plans:read'));
        self::assertTrue(\Core\Identity\Domain\Model\OrganizationRole::Support->hasPermission('usage:read'));
        self::assertFalse(\Core\Identity\Domain\Model\OrganizationRole::Support->hasPermission('invoices:write'));

        $projectId = $this->projectWithOneSubscriber();

        // ADR-0080 §1 — the onboarding belongs to the project (every
        // member of its organisation reads it); what the project **earns**
        // belongs to the finance level.
        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);
        $page = $this->get("/admin/projects/{$projectId}/indicators")->assertOk();
        $page->assertSee('Une offre publiée')
            ->assertDontSee('Revenu récurrent mensuel')
            ->assertDontSee('Encaissé ce mois')
            ->assertDontSee('Créances par ancienneté');
    }

    #[Test]
    public function a_finance_role_reads_the_figures_of_the_project(): void
    {
        $projectId = $this->projectWithOneSubscriber();

        // `invoices:write` is an organisation permission (`org_owner`,
        // `org_admin`, `org_finance`); the platform admin holds it by
        // his `*`.
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);
        $page = $this->get("/admin/projects/{$projectId}/indicators")->assertOk();

        $page->assertSee('Revenu récurrent mensuel')
            ->assertSee('Encaissé ce mois')
            ->assertSee('Créances par ancienneté');
    }

    #[Test]
    public function the_checklist_is_computed_from_the_project_and_signals_an_active_project_that_is_not_ready(): void
    {
        $projectId = $this->projectWithOneSubscriber();

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $page = $this->get("/admin/projects/{$projectId}/indicators")->assertOk();

        // The seven items ADR-0080 §4 asks for.
        $page->assertSee('Une clé d\'API a déjà servi')
            ->assertSee('Un webhook actif et testé')
            ->assertSee('Une application prestataire enregistrée')
            ->assertSee('Une offre publiée')
            ->assertSee('Un portail personnalisé')
            ->assertSee('Une session de notification enregistrée')
            ->assertSee('Attribution des usages obligatoire');

        // Nothing is wired yet and the project is active: it is signalled.
        // The sentence is static text in the template, so the apostrophe is
        // not escaped there — the comparison must not escape it either.
        $page->assertSee('Ce projet est actif, mais il n\'est pas encore prêt à encaisser.', false);

        // The published plan carries no limit, so the attribution setting
        // does not concern this project: it is not a failure.
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $page->getContent();
        $html = is_string($content) ? $content : '';
        self::assertMatchesRegularExpression(
            '/Attribution des usages obligatoire\s*<span class="sr-only">\(sans objet\)<\/span>/',
            $html,
        );

        // Wire the two mandatory items the project is missing.
        $this->wireApiKey($projectId);
        $this->wireWebhook($projectId);

        $wired = $this->get("/admin/projects/{$projectId}/indicators")->assertOk();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $wiredContent = $wired->getContent();
        $wiredHtml = is_string($wiredContent) ? $wiredContent : '';
        self::assertMatchesRegularExpression('/Une clé d(&#039;|\')API a déjà servi\s*<span class="sr-only">\(fait\)<\/span>/', $wiredHtml);
        self::assertMatchesRegularExpression('/Un webhook actif et testé\s*<span class="sr-only">\(fait\)<\/span>/', $wiredHtml);
        self::assertStringNotContainsString('Ce projet est actif, mais il n\'est pas encore prêt à encaisser.', $wiredHtml);
    }

    #[Test]
    public function a_key_that_has_not_served_recently_does_not_count_as_used(): void
    {
        $projectId = $this->projectWithOneSubscriber();

        // A key that served eight months ago: ADR-0080 §4 asks for a key
        // « utilisée récemment », not one that merely exists.
        $issued = app(\Core\Identity\Contracts\IssueApiKey::class)->issue(
            new \Core\Identity\Contracts\IssueApiKeyCommand($projectId, \Core\Identity\Contracts\ApiKeyScopes::ALL),
            actor: 'test',
            correlationId: null,
        );
        DB::table('api_keys')->where('id', $issued->apiKeyId)->update([
            'last_used_at' => now()->subMonths(8),
        ]);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $page = $this->get("/admin/projects/{$projectId}/indicators")->assertOk();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $page->getContent();
        $html = is_string($content) ? $content : '';
        self::assertMatchesRegularExpression('/Une clé d(&#039;|\')API a déjà servi\s*<span class="sr-only">\(à faire\)<\/span>/', $html);
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

    private function wireApiKey(string $projectId): void
    {
        $issued = app(\Core\Identity\Contracts\IssueApiKey::class)->issue(
            new \Core\Identity\Contracts\IssueApiKeyCommand($projectId, \Core\Identity\Contracts\ApiKeyScopes::ALL),
            actor: 'test',
            correlationId: null,
        );

        DB::table('api_keys')->where('id', $issued->apiKeyId)->update(['last_used_at' => now()]);
    }

    private function wireWebhook(string $projectId): void
    {
        $endpoint = app(\Core\Webhook\Contracts\RegisterWebhookEndpoint::class)->register(
            new \Core\Webhook\Contracts\RegisterWebhookEndpointCommand($projectId, 'https://example.com/hook', ['*']),
        );

        // A delivery that actually landed — an endpoint that was never
        // called proves nothing (ADR-0080 §4).
        app(\Core\Webhook\Domain\Port\WebhookDeliveryRepository::class)->create(new \Core\Webhook\Domain\Model\WebhookDelivery(
            id: (string) Str::uuid(),
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'payment.status_changed',
            payload: [],
            status: \Core\Webhook\Domain\Model\WebhookDeliveryStatus::Delivered,
            attempts: 1,
            nextRetryAt: null,
            lastAttemptAt: new DateTimeImmutable,
            lastError: null,
            deliveredAt: new DateTimeImmutable,
        ));
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
