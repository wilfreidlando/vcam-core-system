<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Http\Controllers;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayCallbackAuthenticator;
use Core\Payment\PaymentStatus;
use Core\Payment\Presentation\Http\Controllers\MyCoolPayWebhookController;
use Core\Support\Clock;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit-style integration test for `MyCoolPayWebhookController`. Uses a
 * fake `PaymentStatusChangeHandler` (no real DB) and a fake
 * `PaymentStatusMapper` so the test stays inside the module's
 * `Contracts` boundary (G3c) — the handler's own behaviour (resolving
 * the Payment, crediting a wallet, publishing to the outbox) has its
 * own dedicated test, `HandlePaymentStatusChangedUseCaseTest`.
 *
 * Security review finding: this controller used to have NO real
 * authenticity check — every test body below now carries a correctly
 * computed MD5 signature (`signedBody()`), per MyCoolPay's documented
 * formula (API docs §7.5), or is specifically testing that an
 * unauthentic request is rejected.
 *
 * Why Integration, not Unit: the controller's dependencies come from
 * the Laravel container (test framework, container facade). The actual
 * HTTP handler is exercised; the persistence layer is replaced by a
 * fake. Net result: fast, hermetic, no cross-module reach.
 */
final class MyCoolPayWebhookControllerTest extends TestCase
{
    private const PRIVATE_KEY = 'sk';

    private FakePaymentStatusChangeHandler $handler;

    private MyCoolPayWebhookController $webhook;

    #[Test]
    public function a_valid_payin_webhook_is_handed_to_the_status_change_handler(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'app_transaction_ref' => 'order-42',
            'transaction_ref' => 'tx-abc',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
            // `transaction_reason` deliberately carries something
            // DIFFERENT from `transaction_message` here — proof the
            // controller maps `reason` from the latter, not the former
            // (review finding: MyCoolPay echoes `transaction_reason`
            // back verbatim from what the caller sent at initiation; it
            // is never a real account of what happened. `transaction_message`
            // is MyCoolPay's own human-readable one).
            'transaction_reason' => 'Order #42',
            'transaction_message' => 'Votre transaction a été effectuée avec succès !',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->handler->handled);
        $event = $this->handler->handled[0];
        self::assertSame('my-coolpay', $event->providerCode);
        self::assertSame('tx-abc', $event->transactionRef);
        self::assertSame('order-42', $event->appTransactionRef);
        self::assertSame(PaymentStatus::Succeeded, $event->status);
        self::assertSame('Votre transaction a été effectuée avec succès !', $event->reason);
    }

    #[Test]
    public function a_payout_webhook_is_ignored(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-payout',
            'transaction_type' => 'PAYOUT',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_webhook_from_an_unregistered_application_is_rejected(): void
    {
        // Signed correctly, but for an `application` (public key) that no
        // Project has registered — ADR-0051's per-application model has
        // no private key to verify it against, so it cannot be trusted
        // regardless of the signature's own shape.
        $body = self::signedBody([
            'application' => 'pub-UNREGISTERED-application',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled, 'An unregistered application must not be handled.');
    }

    #[Test]
    public function a_webhook_missing_transaction_ref_is_ignored(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_webhook_missing_transaction_status_is_ignored(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function an_unknown_status_is_ignored(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'PARTIALLY_PAID',
        ]);

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_canceled_webhook_maps_to_the_canceled_canonical_status(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-cancel',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'CANCELED',
        ]);

        $this->invoke($this->webhookRequest($body));

        self::assertSame(PaymentStatus::Canceled, $this->handler->handled[0]->status);
    }

    #[Test]
    public function a_failed_webhook_maps_to_the_failed_canonical_status(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-fail',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'FAILED',
        ]);

        $this->invoke($this->webhookRequest($body));

        self::assertSame(PaymentStatus::Failed, $this->handler->handled[0]->status);
    }

    #[Test]
    public function an_empty_body_carries_no_signature_and_is_rejected_as_unauthentic(): void
    {
        // Security review finding: an empty/malformed body used to be
        // treated as "structurally valid but missing fields" (200,
        // ignored). Now authenticity is checked FIRST and
        // unconditionally — a body with no `signature` field can never
        // pass, so this is correctly a 403, not a soft ignore.
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

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_missing_signature_is_rejected(): void
    {
        $body = [
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
            // no 'signature' key at all
        ];

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_tampered_signature_is_rejected(): void
    {
        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
        ]);
        // Attacker changes the amount after the signature was computed
        // for a different one — the classic tamper scenario.
        $body['transaction_amount'] = 999999;

        $response = $this->invoke($this->webhookRequest($body));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_request_from_an_untrusted_ip_is_rejected_even_with_a_valid_signature(): void
    {
        $controller = new MyCoolPayWebhookController(
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            clock: $this->getClock(),
            applications: $this->makeApplications(),
            authenticator: new MyCoolPayCallbackAuthenticator(['203.0.113.5']), // not the test client's IP
            logger: new NullLogger,
        );

        $body = self::signedBody([
            'application' => 'pub-test-1234',
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
        ]);

        $response = $controller($this->webhookRequest($body));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    #[Test]
    public function a_webhook_with_no_application_field_is_rejected(): void
    {
        // Security review finding: this used to fall back to a single
        // platform-wide config key when present. Under the per-application
        // model (ADR-0051), there is no such fallback at all — a missing
        // `application` field gives no basis to resolve a private key,
        // so the only safe behaviour is to refuse.
        $controller = new MyCoolPayWebhookController(
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            clock: $this->getClock(),
            applications: $this->makeApplications(),
            authenticator: new MyCoolPayCallbackAuthenticator([]),
            logger: new NullLogger,
        );

        $body = [
            'transaction_ref' => 'tx-x',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
            // No 'application' field, so no private key can be resolved
            // to check any signature against.
            'signature' => 'irrelevant',
        ];

        $response = $controller($this->webhookRequest($body));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->handler->handled);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FakePaymentStatusChangeHandler;

        $this->webhook = new MyCoolPayWebhookController(
            statusChangeHandler: $this->handler,
            statusMapper: $this->getStatusMapper(),
            clock: $this->getClock(),
            applications: $this->makeApplications(),
            // Empty allowlist = permissive on IP (matches
            // config/providers.php's own documented default) — this
            // test class is about signature/business-filter behaviour,
            // not IP trust, which has its own dedicated test above.
            authenticator: new MyCoolPayCallbackAuthenticator([]),
            logger: new NullLogger,
        );
    }

    private function makeApplications(): ProviderApplicationRepository
    {
        $privateKey = self::PRIVATE_KEY;

        return new class($privateKey) implements ProviderApplicationRepository
        {
            public function __construct(private string $privateKey) {}

            public function save(ProviderApplication $application): void
            {
                throw new RuntimeException('Not needed by this fake.');
            }

            public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication
            {
                throw new RuntimeException('Not needed by this fake — inbound path only.');
            }

            public function findByProviderAndPublicKey(string $providerCode, string $publicKey): ?ProviderApplication
            {
                if ($providerCode === 'my-coolpay' && $publicKey === 'pub-test-1234') {
                    return new ProviderApplication(
                        projectId: 'project-1',
                        providerCode: 'my-coolpay',
                        publicKey: 'pub-test-1234',
                        privateKey: $this->privateKey,
                    );
                }

                return null;
            }

            public function findAllByProject(string $projectId): array
            {
                throw new RuntimeException('Not needed by this fake — inbound path only.');
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function signedBody(array $body, string $privateKey = self::PRIVATE_KEY): array
    {
        $body['signature'] = md5(
            self::field($body, 'transaction_ref')
            .self::field($body, 'transaction_type')
            .self::field($body, 'transaction_amount')
            .self::field($body, 'transaction_currency')
            .self::field($body, 'transaction_operator')
            .$privateKey,
        );

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function field(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) || is_int($value) ? (string) $value : '';
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
