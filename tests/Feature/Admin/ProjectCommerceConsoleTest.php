<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * The commerce settings of a project in the console (ADR-0075 §4, ADR-0071 §4,
 * ADR-0074): its portal, its billing settings, what its customers consume.
 *
 * What the operator writes reaches the portal, and every change is audited.
 */
final class ProjectCommerceConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_portal_settings_are_edited_validated_and_audited(): void
    {
        $projectId = $this->project();
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get("/admin/projects/{$projectId}/portal")->assertOk()->assertSee('Portail client')->assertSee('Adresses de retour autorisées');

        $this->post("/admin/projects/{$projectId}/portal", [
            'display_name' => 'Mon Auto-École',
            'primary_color' => '#0f766e',
            'locale' => 'fr',
            'allowed_return_urls' => "https://app.mon-saas.cm/compte\nhttps://admin.mon-saas.cm",
            'allow_self_cancel' => '1',
            'allow_plan_change' => '1',
            'payment_channels' => ['mtn_momo'],
        ])->assertRedirect("/admin/projects/{$projectId}/portal");

        $this->get("/admin/projects/{$projectId}/portal")
            ->assertSee('#0f766e')->assertSee('https://admin.mon-saas.cm')->assertSee('Mon Auto-École');

        // What the console wrote is what the portal API serves.
        self::assertSame('#0f766e', DB::table('portal_settings')->where('project_id', $projectId)->value('primary_color'));
        self::assertSame(1, DB::table('audit_log')->where('action', 'portal.settings_changed')->count());

        // A bad value is refused with a sentence and nothing is written.
        $this->post("/admin/projects/{$projectId}/portal", ['display_name' => 'X', 'primary_color' => 'red'])->assertSessionHasErrors('resource');
        $this->post("/admin/projects/{$projectId}/portal", ['display_name' => 'X', 'allowed_return_urls' => 'javascript:alert(1)'])->assertSessionHasErrors('resource');
        self::assertSame('#0f766e', DB::table('portal_settings')->where('project_id', $projectId)->value('primary_color'));
    }

    #[Test]
    public function the_commerce_settings_are_edited_with_their_rules_in_code_and_audited(): void
    {
        $projectId = $this->project();
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get("/admin/projects/{$projectId}/commerce-settings")->assertOk()->assertSee('Facturation')->assertSee('FACT');

        $this->post("/admin/projects/{$projectId}/commerce-settings", [
            'timezone' => 'Africa/Douala',
            'default_currency' => 'xaf',
            'renewal_lead_days' => 5,
            'incomplete_expiry_hours' => 48,
            'invoice_prefix' => 'AUTO',
            'default_tax_rate_percent' => '19.25',
            'manual_receipt_threshold' => 100000,
            'meter_mappings' => "notification.whatsapp = whatsapp_messages\n",
            'refund_failed_usage' => '1',
            'require_customer_ref' => '1',
        ])->assertRedirect("/admin/projects/{$projectId}/commerce-settings");

        $row = DB::table('billing_settings')->where('project_id', $projectId)->first();
        self::assertNotNull($row);
        self::assertSame('XAF', $row->default_currency);
        self::assertSame('AUTO', $row->invoice_prefix);
        self::assertEquals(1925, $row->default_tax_rate_bps, 'A percentage typed by a human is stored as basis points, never a float.');
        self::assertSame('{"notification.whatsapp":"whatsapp_messages"}', str_replace(' ', '', is_string($row->meter_mappings) ? $row->meter_mappings : ''));
        self::assertTrue((bool) $row->require_customer_ref);
        self::assertSame(1, DB::table('audit_log')->where('action', 'billing.settings_changed')->count());

        // The schema holds no rule: the code refuses what is wrong.
        foreach ([
            ['invoice_prefix' => 'a'],
            ['renewal_lead_days' => 999],
            ['timezone' => 'Mars/Olympus'],
            ['meter_mappings' => 'Bad Capability = meter'],
        ] as $bad) {
            $this->post("/admin/projects/{$projectId}/commerce-settings", $bad + [
                'timezone' => 'Africa/Douala', 'default_currency' => 'XAF', 'renewal_lead_days' => 5,
                'incomplete_expiry_hours' => 48, 'invoice_prefix' => 'AUTO',
            ])->assertSessionHasErrors();
        }

        self::assertSame('AUTO', DB::table('billing_settings')->where('project_id', $projectId)->value('invoice_prefix'));
    }

    #[Test]
    public function the_usage_page_lists_counters_filters_the_ones_near_their_limit_and_shows_the_journal(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);
        foreach (['AE0001' => 9, 'AE0002' => 2] as $ref => $used) {
            $this->subscribe($projectId, $ref);
            app(ConsumeUsage::class)->consume(new ConsumeUsageCommand($projectId, $ref, 'students', $used, 'seed-'.$ref, 'api'));
        }

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $all = $this->get("/admin/projects/{$projectId}/usage")->assertOk();
        $all->assertSee('AE0001')->assertSee('AE0002');

        // Only the customer at 90 % of 10 shows under « Au-delà de 80 % ».
        $near = $this->get("/admin/projects/{$projectId}/usage?filter=over80")->assertOk();
        $near->assertSee('AE0001')->assertDontSee('AE0002');

        $this->get("/admin/projects/{$projectId}/usage?view=journal")->assertOk()->assertSee('seed-AE0001')->assertSee('api');
        $this->get("/admin/projects/{$projectId}/usage?customer=AE0002")->assertOk()->assertDontSee('AE0001');

        // The journal is read-only: the console offers no way to edit it.
        $this->post("/admin/projects/{$projectId}/usage/adjust", [])->assertStatus(404);
    }

    #[Test]
    public function an_operator_without_the_permission_gets_a_403_on_each_page(): void
    {
        $projectId = $this->project();
        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);

        $this->get("/admin/projects/{$projectId}/portal")->assertStatus(403);
        $this->get("/admin/projects/{$projectId}/commerce-settings")->assertStatus(403);
        $this->get("/admin/projects/{$projectId}/usage")->assertStatus(403);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function plan(string $projectId): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));
    }

    private function subscribe(string $projectId, string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Client '.$ref, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $ref, planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-'.$ref, actor: 'test', correlationId: null,
        ));
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $html = $this->html($enroll);
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function html(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
