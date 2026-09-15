<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Http\Controllers;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\PaymentStatus;
use Core\Payment\Presentation\Http\Controllers\MyCoolPayWebhookController;
use Core\Support\Clock;
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderNotConfigured;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Unit-style integration test for `MyCoolPayWebhookController`. Uses a
 * fake `PaymentStatusChangeHandler` (no real DB) and a fake
 * `PaymentStatusMapper` so the test stays inside the module's
 * `Contracts` boundary (G3c) — the handler's own behaviour (resolving
 * the Payment, crediting a wallet, publishing to the outbox) has its
 * own dedicated test, `HandlePaymentStatusChangedUseCaseTest`.
 *
 * Why Integration, not Unit: the controller's dependencies come from
 * the Laravel container (test framework, container facade). The actual
 * HTTP handler is exercised; the persistence layer is replaced by a
 * fake. Net result: fast, hermetic, no cross-module reach.
 */
final class MyCoolPayWebhookControllerTest extends TestCase
{
    private FakePaymentStatusChangeHandler $handler;

    private MyCoolPayWebhookController $webhook;

    #[Test]
    public function a_valid_payin_webhook_is_handed_to_the_status_change_handler(): void
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
        self::assertCount(1, $this->handler->handled);
        $event = $this->handler->handled[0];
        self::assertSame('my-coolpay', $event->providerCode);
        self::assertSame('tx-abc', $event->transactionRef);
        self::assertSame('order-42', $event->appTransactionRef);
        self::assertSame(PaymentStatus::Succeeded, $event->status);
        self::assertSame('Order #42', $event->reason);
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
        self::assertCount(0, $this->handler->handled);
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
        self::assertCount(0, $this->handler->handled, 'A different application key must not be handled.');
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
        self::assertCount(0, $this->handler->handled);
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
        self::assertCount(0, $this->handler->handled);
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
        self::assertCount(0, $this->handler->handled);
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

        self::assertSame(PaymentStatus::Canceled, $this->handler->handled[0]->status);
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

        self::assertSame(PaymentStatus::Failed, $this->handler->handled[0]->status);
    }

    #[Test]
    public function an_empty_body_is_accepted_and_ignored(): void
    {
        // Laravel's `Request::json()->all()` always returns an array
        // (empty for non-JSON bodies). The controller treats an empty
        // body as missing-fields → not handled → 200 with `ignored` (so
        // MyCoolPay does not retry forever).
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
        self::assertCount(0, $this->handler->handled);
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
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            clock: $this->getClock(),
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
        self::assertCount(1, $this->handler->handled);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FakePaymentStatusChangeHandler;

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

        $this->webhook = new MyCoolPayWebhookController(
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            clock: $this->getClock(),
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
}

/**
 * In-memory PaymentStatusChangeHandler for tests. Records every
 * `handle()` call so the test can assert what the controller decided to
 * hand off, without exercising the use case's own persistence/outbox/
 * wallet-crediting behaviour (covered separately).
 */
final class FakePaymentStatusChangeHandler implements PaymentStatusChangeHandler
{
    /** @var list<PaymentStatusChanged> */
    public array $handled = [];

    public function handle(PaymentStatusChanged $event): void
    {
        $this->handled[] = $event;
    }
}
