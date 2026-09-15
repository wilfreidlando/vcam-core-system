<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Integration\Application;

use Core\Notification\Contracts\Events\NotificationStatusChanged;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Contracts\SendNotification;
use Core\Notification\Contracts\SendNotificationCommand;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full Lot 5 orchestration path (ADR-0041) exercised for real: a
 * template lookup, a send through the fallback cascade, and a
 * provider-reported status change — all against real PostgreSQL. Only
 * the outbound "call to the provider" edge is replaced
 * (`FakeNotificationChannelProvider` swapped in for the typed registry),
 * exactly the same pattern as `PaymentToWalletIntegrationTest` and
 * `WebhookRelayAndDeliveryIntegrationTest`.
 */
final class SendNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000060';

    private FakeNotificationChannelProvider $gateway;

    #[Test]
    public function a_notification_is_sent_and_persisted_with_the_pinned_provider(): void
    {
        $this->seedApprovedTemplate();

        $view = app(SendNotification::class)->send($this->command());

        self::assertSame(NotificationStatus::Sent, $view->status);

        $row = DB::table('notifications')->where('id', $view->id)->first();
        self::assertNotNull($row);
        self::assertSame('fake-provider', $row->provider_code);
        self::assertSame('msg-fake-1', $row->provider_message_id);

        $deliveries = DB::table('notification_deliveries')->where('notification_id', $view->id)->get();
        self::assertCount(1, $deliveries);
        $delivery = $deliveries->first();
        self::assertNotNull($delivery);
        self::assertIsInt($delivery->attempt);
        self::assertSame(1, $delivery->attempt);
    }

    #[Test]
    public function a_provider_reported_status_change_transitions_the_notification_and_publishes_the_canonical_event(): void
    {
        $this->seedApprovedTemplate();
        $view = app(SendNotification::class)->send($this->command());

        app(NotificationStatusChangeHandler::class)->handle(new NotificationStatusChanged(
            providerCode: 'fake-provider',
            providerMessageId: 'msg-fake-1',
            status: NotificationStatus::Delivered,
        ));

        self::assertSame(
            NotificationStatus::Delivered->value,
            DB::table('notifications')->where('id', $view->id)->value('status'),
        );
        self::assertSame(
            1,
            DB::table('event_outbox')
                ->where('type', 'notification.delivered')
                ->where('project_id', self::PROJECT_ID)
                ->count(),
        );
    }

    #[Test]
    public function replaying_the_same_status_change_publishes_the_event_only_once(): void
    {
        $this->seedApprovedTemplate();
        $view = app(SendNotification::class)->send($this->command());

        $change = new NotificationStatusChanged('fake-provider', 'msg-fake-1', NotificationStatus::Delivered);
        app(NotificationStatusChangeHandler::class)->handle($change);
        app(NotificationStatusChangeHandler::class)->handle($change);

        self::assertSame(
            1,
            DB::table('event_outbox')
                ->where('type', 'notification.delivered')
                ->where('project_id', self::PROJECT_ID)
                ->count(),
        );
        self::assertSame(
            NotificationStatus::Delivered->value,
            DB::table('notifications')->where('id', $view->id)->value('status'),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider'));
        $registry = new FakeNotificationChannelProviderRegistry;
        $registry->register($this->gateway);
        $this->app->instance(NotificationChannelProviderRegistry::class, $registry);

        // The real ConfigNotificationProviderRouter stays wired (routing
        // itself is proven for real); only the channel table gets a
        // routable entry for this test's fake provider, since
        // config/notification_channels.php ships empty (ADR-0041 — no
        // real adapter exists yet to route to).
        config(['notification_channels.default.whatsapp' => ['fake-provider']]);
    }

    private function seedApprovedTemplate(): void
    {
        DB::table('notification_templates')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => self::PROJECT_ID,
            'key' => 'booking_confirmed',
            'channel' => 'whatsapp',
            'kind' => 'session',
            'template_status' => 'approved',
            'version' => 1,
            'body' => json_encode(['text' => 'Hello {{name}}'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function command(): SendNotificationCommand
    {
        return new SendNotificationCommand(
            projectId: self::PROJECT_ID,
            channel: NotificationChannel::fromString('whatsapp'),
            toPartyRef: (string) Str::uuid(),
            template: 'booking_confirmed',
            variables: ['name' => 'Awa'],
            idempotencyKey: (string) Str::uuid(),
        );
    }
}
