<?php

declare(strict_types=1);

namespace Tests\Integration;

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
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0074 §5 — the Core's own usage is metered by the same authority:
 * a WhatsApp message is a unit of the customer's plan.
 *
 * App-level on purpose (G3c): the scenario orchestrates `identity`,
 * `billing` and `notification` fixtures together.
 */
final class NotificationQuotaHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_message_beyond_the_plan_is_refused_with_no_row_and_no_provider_call(): void
    {
        Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201)]);

        [$token, $projectId] = $this->project();
        $this->meterNotifications($projectId, limit: 1);

        $first = $this->send($token, 'm1');
        $first->assertStatus(201)->assertJsonPath('status', 'sent');

        $second = $this->send($token, 'm2');
        $second->assertStatus(429)
            ->assertJsonPath('type', 'https://errors.core-system.internal/quota-exceeded');

        // The refused message left no notification, no delivery attempt, no unit.
        self::assertSame(1, DB::table('notifications')->count());
        self::assertEquals(1, DB::table('usage_counters')->value('used'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_replayed_message_counts_once(): void
    {
        Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201)]);

        [$token, $projectId] = $this->project();
        $this->meterNotifications($projectId, limit: 5);

        $this->send($token, 'same')->assertStatus(201);
        $this->send($token, 'same')->assertStatus(201);

        self::assertSame(1, DB::table('notifications')->count());
        self::assertEquals(1, DB::table('usage_counters')->value('used'));
    }

    #[Test]
    public function a_message_that_no_provider_could_deliver_gives_its_unit_back(): void
    {
        Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['error' => 'down'], 500)]);

        [$token, $projectId] = $this->project();
        $this->meterNotifications($projectId, limit: 5);

        $this->send($token, 'doomed')->assertJsonPath('status', 'failed');

        self::assertEquals(0, DB::table('usage_counters')->value('used'));
        self::assertSame(2, DB::table('usage_records')->count()); // the unit and its refund
    }

    #[Test]
    public function a_project_without_a_mapping_is_not_metered(): void
    {
        Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201)]);

        [$token, $projectId] = $this->project();
        $this->registerSession($projectId);

        $this->send($token, 'free')->assertStatus(201);

        self::assertSame(0, DB::table('usage_counters')->count());
    }

    #[Test]
    public function a_customer_without_the_capability_in_its_plan_cannot_send(): void
    {
        Http::preventStrayRequests();

        [$token, $projectId] = $this->project();
        $this->meterNotifications($projectId, limit: 5, customerMeter: 'storage_mb');

        $this->send($token, 'x')->assertStatus(403);

        self::assertSame(0, DB::table('notifications')->count());
        Http::assertNothingSent();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['notifications:write']), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    private function registerSession(string $projectId): void
    {
        app(ChannelSessionRepository::class)->save(new ChannelSession((string) Str::uuid(), $projectId, 'whatsapp', 'ecole', new DateTimeImmutable));
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function send(string $token, string $reference): TestResponse
    {
        return $this->postJson('/v1/notifications/text', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'candidat-1',
            'text' => 'Bonjour',
            'variables' => ['phone_number' => '+237657467941', 'session' => 'ecole', 'customer_ref' => 'AE0001'],
            'client_reference' => $reference,
        ], ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * The project maps the WhatsApp capability to a meter, and the
     * customer `AE0001` is on a plan that limits it.
     */
    private function meterNotifications(string $projectId, int $limit, string $customerMeter = 'whatsapp_messages'): void
    {
        $this->registerSession($projectId);

        app(BillingSettingsRepository::class)->save(new BillingSettings(
            projectId: $projectId,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
            meterMappings: ['notification.whatsapp' => 'whatsapp_messages'],
        ));

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
            features: ['messages' => ['type' => 'limit', 'meter' => $customerMeter, 'limit' => $limit, 'reset' => 'period', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0001', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-1', actor: 'test', correlationId: null,
        ));
    }
}
