<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0074 §5 — the project declares which capability of the Core feeds
 * which meter.
 *
 * A client application knows the capability it consumes — it sends
 * `notification.whatsapp`, it does not know that the plan calls the meter
 * `whatsapp_messages`. The Core therefore exposes the rights **twice**:
 * once under the plan's feature key, once under the capability's name.
 *
 * The two must carry the same numbers: the client and the Core can then
 * never disagree about what is left.
 */
final class CapabilityEntitlementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_declared_capability_is_exposed_under_its_own_name(): void
    {
        [$token, $projectId] = $this->project();
        $this->subscribe($projectId);

        $this->declareMapping($projectId, ['notification.whatsapp' => 'whatsapp_messages']);

        $entitlements = $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($token))
            ->assertOk()
            ->assertJsonPath('active', true);

        $all = collect((array) $entitlements->json('entitlements'));

        $feature = $all->firstWhere('key', 'whatsapp_messages');
        $capability = $all->firstWhere('key', 'notification.whatsapp');

        self::assertIsArray($feature);
        self::assertIsArray($capability);

        // The same numbers, under the capability's name.
        self::assertSame('whatsapp_messages', $capability['meter']);
        self::assertSame($feature['limit'], $capability['limit']);
        self::assertSame($feature['used'], $capability['used']);
        self::assertSame($feature['remaining'], $capability['remaining']);
    }

    #[Test]
    public function a_capability_that_is_not_declared_is_not_exposed(): void
    {
        [$token, $projectId] = $this->project();
        $this->subscribe($projectId);

        // No mapping: the plan's meter is known, the capability is not.
        $entitlements = $this->getJson('/v1/customers/AE0001/entitlements', $this->auth($token))
            ->assertOk();

        $all = collect((array) $entitlements->json('entitlements'));

        self::assertNotNull($all->firstWhere('key', 'whatsapp_messages'));
        self::assertNull($all->firstWhere('key', 'notification.whatsapp'));
    }

    /**
     * @param array<string, string> $mappings
     */
    private function declareMapping(string $projectId, array $mappings): void
    {
        app(ManageBillingSettings::class)->save(new SaveBillingSettingsCommand(
            projectId: $projectId,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
            invoicePrefix: 'FACT',
            defaultTaxRateBps: 0,
            manualReceiptApprovalThresholdMinor: 0,
            meterMappings: $mappings,
            refundFailedUsage: true,
            requireCustomerRef: false,
            actor: 'test',
        ), AccessScope::platform());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['usage:read']), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function subscribe(string $projectId): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Auto-école', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public',
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 5_000, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: [
                'whatsapp_messages' => ['type' => 'limit', 'meter' => 'whatsapp_messages', 'limit' => 100, 'reset' => 'never', 'overage' => 'block'],
            ],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0001', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-AE0001', actor: 'test', correlationId: null,
        ));
    }
}
