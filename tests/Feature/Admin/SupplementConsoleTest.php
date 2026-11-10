<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

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
 * ADR-0119 — the packs of quota, in the console.
 *
 * What the operator writes reaches the portal: the catalogue declared in
 * the commerce settings is what the customer is offered, and a pack
 * granted to a subscription creates the supplement **and** its invoice —
 * the allowance itself waits for the payment (ADR-0117 §2).
 */
final class SupplementConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_catalogue_of_packs_is_declared_in_the_commerce_settings(): void
    {
        $projectId = $this->project();
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get("/admin/projects/{$projectId}/commerce-settings")
            ->assertOk()
            ->assertSee('Packs de rechargement');

        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => 'notification.whatsapp = whatsapp_messages',
            'supplement_packs' => 'pack50 | whatsapp_messages | 50 | 5000 | Pack de 50 messages WhatsApp',
        ]))->assertRedirect("/admin/projects/{$projectId}/commerce-settings");

        // The catalogue is written, and the console shows it back.
        $raw = DB::table('billing_settings')->where('project_id', $projectId)->value('supplement_packs');
        self::assertIsString($raw);
        $packs = json_decode($raw, true);
        self::assertIsArray($packs);
        self::assertCount(1, $packs);
        $first = $packs[0];
        self::assertIsArray($first);
        self::assertSame('pack50', $first['code'] ?? null);
        self::assertEquals(50, $first['quantity'] ?? null);

        $this->get("/admin/projects/{$projectId}/commerce-settings")
            ->assertSee('pack50 | whatsapp_messages | 50 | 5000 | Pack de 50 messages WhatsApp');
    }

    #[Test]
    public function a_malformed_pack_is_refused_and_nothing_is_written(): void
    {
        $projectId = $this->project();
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        // Five fields are needed.
        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'supplement_packs' => 'pack50 | whatsapp_messages | 50',
        ]))->assertSessionHasErrors('resource');

        // The quantity is a whole number, at least one.
        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'supplement_packs' => 'pack50 | whatsapp_messages | 0 | 5000 | Pack',
        ]))->assertSessionHasErrors('resource');

        // The meter must be one the project declared.
        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => 'notification.whatsapp = whatsapp_messages',
            'supplement_packs' => 'pack50 | sms | 50 | 5000 | Pack',
        ]))->assertSessionHasErrors('resource');

        $saved = DB::table('billing_settings')->where('project_id', $projectId)->value('supplement_packs');
        self::assertNotSame('pack50', $saved);
    }

    #[Test]
    public function granting_a_pack_creates_the_supplement_its_invoice_and_an_audit_entry(): void
    {
        $projectId = $this->project();
        $subscriptionId = $this->subscribe($projectId);
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => 'notification.whatsapp = whatsapp_messages',
            'supplement_packs' => 'pack50 | whatsapp_messages | 50 | 5000 | Pack de 50 messages WhatsApp',
        ]))->assertRedirect("/admin/projects/{$projectId}/commerce-settings");

        $this->get("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}")
            ->assertOk()
            ->assertSee('Packs accordés')
            ->assertSee('Pack de 50 messages WhatsApp');

        $this->post("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}/supplements", [
            'pack_code' => 'pack50',
            'reason' => 'Geste commercial',
            'nonce' => '11111111-1111-4111-8111-111111111111',
        ])->assertRedirect("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}");

        // The supplement exists, pending: the allowance waits for the
        // payment of the invoice it carries.
        $supplement = DB::table('subscription_supplements')->first();
        self::assertNotNull($supplement);
        self::assertSame('whatsapp_messages', $supplement->meter);
        self::assertEquals(50, $supplement->quantity);
        self::assertSame('pending', $supplement->status);
        self::assertNotNull($supplement->invoice_id);

        // The invoice exists, numbered, and the gesture is audited.
        self::assertSame(1, DB::table('invoices')->where('id', $supplement->invoice_id)->count());
        self::assertSame(1, DB::table('audit_log')->where('action', 'billing.subscription.supplement_granted')->count());
    }

    #[Test]
    public function the_same_nonce_does_not_buy_twice(): void
    {
        $projectId = $this->project();
        $subscriptionId = $this->subscribe($projectId);
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => 'notification.whatsapp = whatsapp_messages',
            'supplement_packs' => 'pack50 | whatsapp_messages | 50 | 5000 | Pack de 50 messages WhatsApp',
        ]));

        $nonce = '22222222-2222-4222-8222-222222222222';

        $this->post("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}/supplements", [
            'pack_code' => 'pack50',
            'nonce' => $nonce,
        ])->assertRedirect("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}");

        // The same form sent twice: one purchase, one invoice.
        $this->post("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}/supplements", [
            'pack_code' => 'pack50',
            'nonce' => $nonce,
        ])->assertRedirect("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}");

        self::assertSame(1, DB::table('subscription_supplements')->count());
        self::assertSame(1, DB::table('invoices')->whereIn('id', DB::table('subscription_supplements')->pluck('invoice_id'))->count());
    }

    #[Test]
    public function a_pack_that_is_not_in_the_catalogue_is_refused(): void
    {
        $projectId = $this->project();
        $subscriptionId = $this->subscribe($projectId);
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->post("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}/supplements", [
            'pack_code' => 'pack_invente',
            'nonce' => '33333333-3333-4333-8333-333333333333',
        ])->assertSessionHasErrors('resource');

        self::assertSame(0, DB::table('subscription_supplements')->count());
        self::assertSame(0, DB::table('invoices')->whereIn('id', DB::table('subscription_supplements')->pluck('invoice_id'))->count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The settings of the project, as the form posts them.
     *
     * @param array<string, string> $plus
     * @return array<string, string>
     */
    private function settings(array $plus = []): array
    {
        return array_merge([
            'timezone' => 'Africa/Douala',
            'default_currency' => 'XAF',
            'renewal_lead_days' => '7',
            'incomplete_expiry_hours' => '72',
            'invoice_prefix' => 'FACT',
            'default_tax_rate_percent' => '0',
            'manual_receipt_threshold' => '0',
            'meter_mappings' => '',
            'supplement_packs' => '',
            'refund_failed_usage' => '1',
        ], $plus);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function subscribe(string $projectId): string
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['whatsapp' => ['type' => 'limit', 'meter' => 'whatsapp_messages', 'limit' => 500, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
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

        $subscription = DB::table('subscriptions')->where('project_id', $projectId)->first();
        self::assertNotNull($subscription);
        $id = $subscription->id;
        self::assertIsString($id);

        return $id;
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = (string) $enroll->getContent();
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
