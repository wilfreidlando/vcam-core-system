<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Http\Controllers;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\PaymentStatus;
use Core\Payment\Presentation\Http\Controllers\MyCoolPayWebhookController;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderNotConfigured;
use Core\Support\Integration\CorrelationIdProvider;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Unit-style integration test for `MyCoolPayWebhookController`. Uses a
 * fake `EventOutbox` (no real DB) and a fake `PaymentStatusMapper`
 * so the test stays inside the module's `Contracts` boundary (G3c).
 *
 * Why Integration, not Unit: the controller's dependencies come from
 * the Laravel container (test framework, container facade). The actual
 * HTTP handler is exercised; the persistence layer is replaced by a
 * fake. Net result: fast, hermetic, no cross-module reach.
 */
final class MyCoolPayWebhookControllerTest extends TestCase
{
    private FakeEventOutbox $outbox;

    private MyCoolPayWebhookController $webhook;

    #[Test]
    public function a_valid_payin_webhook_appends_a_payment_status_changed_event(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'app_transaction_ref' => 'order-42',
            'transaction_ref' => 'tx-abc',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'SUCCESS',
            'transaction_reason' => 'Order #42',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->outbox->appended);
        $event = $this->outbox->appended[0];
        self::assertSame('payment.status_changed', $event->type);
        self::assertSame('corr-test', $event->correlationId);
        self::assertSame('01TESTID000000000000000000', $event->id);
        self::assertSame('pub-test-1234', $event->projectId);
        $payload = $event->payload;
        self::assertSame('my-coolpay', $payload['provider_code']);
        self::assertSame('tx-abc', $payload['transaction_ref']);
        self::assertSame('order-42', $payload['app_transaction_ref']);
        self::assertSame('succeeded', $payload['status']);
        self::assertSame('Order #42', $payload['reason']);
    }

    #[Test]
    public function a_payout_webhook_is_ignored(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-payout',
            'transaction_type' => 'PAYOUT',
            'transaction_status' => 'SUCCESS',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended);
    }

    #[Test]
    public function a_webhook_from_a_different_application_is_rejected(): void
    {
        $body = [
            'application' => 'pub-OTHER-application',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'SUCCESS',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended, 'A different application key must not inject events.');
    }

    #[Test]
    public function a_webhook_missing_transaction_ref_is_ignored(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'SUCCESS',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended);
    }

    #[Test]
    public function a_webhook_missing_transaction_status_is_ignored(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended);
    }

    #[Test]
    public function an_unknown_status_is_ignored(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'PARTIALLY_PAID',
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended);
    }

    #[Test]
    public function a_canceled_webhook_maps_to_the_canceled_canonical_status(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-cancel',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'CANCELED',
        ];

        $this->invoke($this->webhookRequest($body));

        $payload = $this->outbox->appended[0]->payload;
        self::assertSame('canceled', $payload['status']);
    }

    #[Test]
    public function a_failed_webhook_maps_to_the_failed_canonical_status(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-fail',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'FAILED',
        ];

        $this->invoke($this->webhookRequest($body));

        $payload = $this->outbox->appended[0]->payload;
        self::assertSame('failed', $payload['status']);
    }

    #[Test]
    public function an_empty_body_is_accepted_and_ignored(): void
    {
        // Laravel's `Request::json()->all()` always returns an array
        // (empty for non-JSON bodies). The controller treats an empty
        // body as missing-fields → no event published → 200 with
        // `ignored` (so MyCoolPay does not retry forever).
        $request = Request::create(
            '/webhooks/my-coolpay',
            'POST',
            [],
            [],
            [],
            [],
            '',
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->invoke($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->outbox->appended);
    }

    #[Test]
    public function missing_config_registry_still_accepts_webhooks_but_skips_key_check(): void
    {
        // Build a fresh controller with a config registry that doesn't
        // know about 'my-coolpay' — the application-key check should be
        // skipped, the rest of the validation should still apply.
        $emptyRegistry = new class implements ProviderConfigRegistry
        {
            public function configFor(ProviderCode $code): \Core\Support\Integration\Configuration\ProviderConfig
            {
                throw new ProviderNotConfigured($code);
            }

            public function defaultCredentialsFor(ProviderCode $code): \Core\Support\Integration\Credentials
            {
                throw new ProviderNotConfigured($code);
            }

            public function has(ProviderCode $code): bool
            {
                return false;
            }

            public function availableProviders(): array
            {
                return [];
            }
        };

        $controller = new MyCoolPayWebhookController(
            outbox: $this->outbox,
            statusMapper: $this->getStatusMapper(),
            identifier: $this->getIdentifier(),
            clock: $this->getClock(),
            correlationIds: $this->getCorrelationIdProvider(),
            configRegistry: $emptyRegistry,
            logger: new NullLogger,
        );

        $body = [
            'application' => 'whatever',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_status' => 'SUCCESS',
        ];

        $response = ($controller)($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->outbox->appended);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new FakeEventOutbox;

        $statusMapper = new class implements PaymentStatusMapper
        {
            public function map(string $raw): PaymentStatus
            {
                return match (strtoupper($raw)) {
                    'SUCCESS' => PaymentStatus::Succeeded,
                    'CANCELED' => PaymentStatus::Canceled,
                    'FAILED' => PaymentStatus::Failed,
                    default => throw new \Core\Support\Integration\Exception\UnknownProviderStatusException(
                        'raw',
                        ProviderCode::fromString('my-coolpay'),
                        $raw,
                    ),
                };
            }
        };

        $configRegistry = new ArrayProviderConfigRegistry([
            'payment' => [
                'my_coolpay' => [
                    'code' => 'my-coolpay',
                    'base_url' => 'https://my-coolpay.com',
                    'auth' => ['kind' => 'api-key'],
                    'credentials' => ['api_key' => 'sk'],
                    'timeouts' => [],
                    'retries' => [],
                    'breaker' => [],
                    'extras' => ['public_key' => 'pub-test-1234'],
                ],
            ],
        ]);

        $identifier = new class implements Identifier
        {
            public function generate(): string
            {
                return '01TESTID000000000000000000';
            }
        };
        $clock = new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-15T10:30:00Z');
            }
        };
        $correlations = new class implements CorrelationIdProvider
        {
            public function current(): string
            {
                return 'corr-test';
            }
        };

        $this->webhook = new MyCoolPayWebhookController(
            outbox: $this->outbox,
            statusMapper: $statusMapper,
            identifier: $identifier,
            clock: $clock,
            correlationIds: $correlations,
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
            '/webhooks/my-coolpay',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    /**
     * Invoke the controller with the given body. Goes through a helper
     * because the controller implements `__invoke`, but PHPStan can't
     * infer that the `$webhook` property (typed as the controller
     * class) is callable — it would think `$this->webhook($req)` is a
     * method call.
     */
    private function invoke(Request $request): \Illuminate\Http\JsonResponse
    {
        return ($this->webhook)($request);
    }

    private function getStatusMapper(): PaymentStatusMapper
    {
        return new class implements PaymentStatusMapper
        {
            public function map(string $raw): PaymentStatus
            {
                return match (strtoupper($raw)) {
                    'SUCCESS' => PaymentStatus::Succeeded,
                    'CANCELED' => PaymentStatus::Canceled,
                    'FAILED' => PaymentStatus::Failed,
                    default => throw new \Core\Support\Integration\Exception\UnknownProviderStatusException(
                        'raw', ProviderCode::fromString('my-coolpay'), $raw,
                    ),
                };
            }
        };
    }

    private function getIdentifier(): Identifier
    {
        return new class implements Identifier
        {
            public function generate(): string
            {
                return '01TESTID000000000000000000';
            }
        };
    }

    private function getClock(): Clock
    {
        return new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-15T10:30:00Z');
            }
        };
    }

    private function getCorrelationIdProvider(): CorrelationIdProvider
    {
        return new class implements CorrelationIdProvider
        {
            public function current(): string
            {
                return 'corr-test';
            }
        };
    }
}

/**
 * In-memory EventOutbox for tests. Records every `append()` so the
 * test can assert what was published.
 */
final class FakeEventOutbox implements EventOutbox
{
    /** @var list<DomainEvent> */
    public array $appended = [];

    public function append(DomainEvent $event): void
    {
        $this->appended[] = $event;
    }
}
