<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Integration\Application;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Domain\Model\WebhookSendOutcome;
use Core\Webhook\Domain\Port\WebhookSender;
use Core\Webhook\Tests\Unit\Fakes\FakeWebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full Lot 4 path (ADR-0040) exercised for real: append a real outbox
 * event, run the relay (`OutboxRelay`), let the queued listener/job chain
 * run synchronously (`QUEUE_CONNECTION=sync` in tests — see phpunit.xml),
 * and assert a real `webhook_deliveries` row lands `delivered`.
 *
 * Only the outbound HTTP call is replaced (`FakeWebhookSender` swapped in
 * for `WebhookSender`); routing (`event_outbox` → relay → fan-out →
 * delivery), persistence, and the retry/DLQ bookkeeping are all real,
 * against real PostgreSQL — the same pattern as
 * `Core\Payment\Tests\Integration\Application\PaymentToWalletIntegrationTest`.
 */
final class WebhookRelayAndDeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000050';

    private FakeWebhookSender $sender;

    #[Test]
    public function a_relayed_event_is_fanned_out_and_delivered_to_a_subscribed_endpoint(): void
    {
        $endpointId = $this->registerEndpoint(['test.event']);

        $this->appendOutboxEvent('test.event', ['amount' => 1_000]);
        $relayed = app(RelayOutboxEvents::class)->relay(10);

        self::assertSame(1, $relayed);
        self::assertCount(1, $this->sender->sent);

        $delivery = DB::table('webhook_deliveries')->where('endpoint_id', $endpointId)->first();
        self::assertNotNull($delivery);
        self::assertSame('delivered', $delivery->status);
        self::assertSame(1, $delivery->attempts);
        self::assertNotNull($delivery->delivered_at);
    }

    #[Test]
    public function an_event_with_no_subscribed_endpoint_is_relayed_but_creates_no_delivery(): void
    {
        $this->registerEndpoint(['some.other.type']);

        $this->appendOutboxEvent('test.event', []);
        $relayed = app(RelayOutboxEvents::class)->relay(10);

        self::assertSame(1, $relayed, 'the outbox row is still marked published even with zero subscribers');
        self::assertSame(0, DB::table('webhook_deliveries')->count());
        self::assertCount(0, $this->sender->sent);
    }

    #[Test]
    public function relaying_twice_never_double_delivers_for_the_same_endpoint_and_event(): void
    {
        $endpointId = $this->registerEndpoint(['test.event']);
        $this->appendOutboxEvent('test.event', []);

        app(RelayOutboxEvents::class)->relay(10);
        // A second relay run finds nothing new (published_at already set),
        // but even a direct re-run of the fan-out use case for the same
        // event would hit the partial unique index (ADR-0040 §4) — this
        // asserts the observable outcome: exactly one delivery.
        app(RelayOutboxEvents::class)->relay(10);

        self::assertSame(1, DB::table('webhook_deliveries')->where('endpoint_id', $endpointId)->count());
    }

    #[Test]
    public function a_failed_delivery_is_recorded_with_an_error_and_stays_pending_for_retry(): void
    {
        $this->registerEndpoint(['test.event']);
        $this->sender->nextOutcome = WebhookSendOutcome::failure(500, 'downstream error');

        $this->appendOutboxEvent('test.event', []);
        app(RelayOutboxEvents::class)->relay(10);

        $delivery = DB::table('webhook_deliveries')->where('event_type', 'test.event')->first();
        self::assertNotNull($delivery);
        self::assertSame('pending', $delivery->status, 'a transient failure stays pending, retry scheduled by Horizon release()');
        self::assertSame(1, $delivery->attempts);
        self::assertSame('downstream error', $delivery->last_error);
        self::assertNotNull($delivery->next_retry_at);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new FakeWebhookSender;
        $this->app->instance(WebhookSender::class, $this->sender);

        // Other integration tests in the full suite deliberately commit
        // real, uncleaned rows outside RefreshDatabase (e.g.
        // WalletConcurrencyTest) and can leave real, unpublished
        // event_outbox rows this test's relay() calls would otherwise
        // count alongside the ones this test creates itself.
        DB::table('event_outbox')->whereNull('published_at')->update(['published_at' => now()]);
    }

    /**
     * @param list<string> $events
     */
    private function registerEndpoint(array $events): string
    {
        $view = app(RegisterWebhookEndpoint::class)->register(new RegisterWebhookEndpointCommand(
            projectId: self::PROJECT_ID,
            url: 'https://example.test/hooks',
            events: $events,
        ));

        return $view->id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendOutboxEvent(string $type, array $payload): void
    {
        app(EventOutbox::class)->append(new DomainEvent(
            id: (string) Str::uuid(),
            projectId: self::PROJECT_ID,
            type: $type,
            payload: $payload,
            correlationId: null,
            occurredAt: now()->toImmutable(),
        ));
    }
}
