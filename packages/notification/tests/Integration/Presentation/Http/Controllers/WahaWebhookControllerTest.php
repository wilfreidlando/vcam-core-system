<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Integration\Presentation\Http\Controllers;

use Core\Notification\Contracts\Events\NotificationStatusChanged;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Contracts\NotificationStatusMapper;
use Core\Notification\Infrastructure\Provider\Waha\RawWahaStatus;
use Core\Notification\Infrastructure\Provider\Waha\WahaStatusMapper;
use Core\Notification\NotificationStatus;
use Core\Notification\Presentation\Http\Controllers\Waha\WahaWebhookController;
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Mirrors `MyCoolPayWebhookControllerTest`: a fake
 * `NotificationStatusChangeHandler` (no real DB), the real
 * `WahaStatusMapper` so the ack-vocabulary translation is exercised
 * end-to-end.
 */
final class WahaWebhookControllerTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    private FakeNotificationStatusChangeHandler $handler;

    private WahaWebhookController $webhook;

    #[Test]
    public function a_valid_ack_webhook_is_handed_to_the_status_change_handler(): void
    {
        $response = $this->invoke($this->webhookRequest([
            'event' => 'message.ack',
            'session' => 'shop-1',
            'payload' => ['id' => 'msg-1', 'ack' => 2],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->handler->handled);
        $event = $this->handler->handled[0];
        self::assertSame('whatsapp-waha', $event->providerCode);
        self::assertSame('msg-1', $event->providerMessageId);
        self::assertSame(NotificationStatus::Delivered, $event->status);
    }

    #[Test]
    public function a_non_ack_event_is_ignored(): void
    {
        $response = $this->invoke($this->webhookRequest([
            'event' => 'session.status',
            'session' => 'shop-1',
            'payload' => ['status' => 'WORKING'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_request_with_a_wrong_secret_is_rejected(): void
    {
        $request = $this->webhookRequest([
            'event' => 'message.ack',
            'payload' => ['id' => 'msg-1', 'ack' => 1],
        ]);
        $request->headers->set('X-Webhook-Secret', 'not-the-right-secret');

        $response = $this->invoke($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_request_missing_the_secret_header_is_rejected(): void
    {
        $request = Request::create('/webhooks/whatsapp-waha', 'POST', [], [], [], [], json_encode([
            'event' => 'message.ack',
            'payload' => ['id' => 'msg-1', 'ack' => 1],
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->invoke($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function an_unknown_ack_value_is_ignored(): void
    {
        $response = $this->invoke($this->webhookRequest([
            'event' => 'message.ack',
            'payload' => ['id' => 'msg-1', 'ack' => 99],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_body_missing_the_payload_is_ignored(): void
    {
        $response = $this->invoke($this->webhookRequest(['event' => 'message.ack']));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function an_error_ack_maps_to_failed(): void
    {
        $this->invoke($this->webhookRequest([
            'event' => 'message.ack',
            'payload' => ['id' => 'msg-x', 'ack' => (int) RawWahaStatus::ERROR],
        ]));

        self::assertSame(NotificationStatus::Failed, $this->handler->handled[0]->status);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FakeNotificationStatusChangeHandler;

        $configRegistry = new ArrayProviderConfigRegistry([
            'notification' => [
                'whatsapp_waha' => [
                    'code' => 'whatsapp-waha',
                    'base_url' => 'https://waha.test',
                    'auth' => ['kind' => 'api-key'],
                    'credentials' => ['api_key' => 'sk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                    'extras' => ['webhook_secret' => self::SECRET],
                ],
            ],
        ]);

        $this->webhook = new WahaWebhookController(
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            configRegistry: $configRegistry,
            logger: new NullLogger,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function webhookRequest(array $body): Request
    {
        $request = Request::create(
            '/webhooks/whatsapp-waha',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Webhook-Secret', self::SECRET);

        return $request;
    }

    private function invoke(Request $request): JsonResponse
    {
        return ($this->webhook)($request);
    }

    private function getStatusMapper(): NotificationStatusMapper
    {
        return new WahaStatusMapper(ProviderCode::fromString('whatsapp-waha'));
    }
}

final class FakeNotificationStatusChangeHandler implements NotificationStatusChangeHandler
{
    /** @var list<NotificationStatusChanged> */
    public array $handled = [];

    public function handle(NotificationStatusChanged $event): void
    {
        $this->handled[] = $event;
    }
}
