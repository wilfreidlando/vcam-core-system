<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Infrastructure\Provider\MyCoolPay;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentAction;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Payment\PaymentFlow;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\InMemoryProviderApplicationRepository;
use Core\Support\Clock;
use Core\Support\Integration\Auth\ApiKeyAuthProvider;
use Core\Support\Integration\Auth\BasicAuthProvider;
use Core\Support\Integration\Auth\BearerAuthProvider;
use Core\Support\Integration\CircuitBreaker\InMemoryCircuitBreaker;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\ExternalApiCallerImpl;
use Core\Support\Integration\LaravelContextCorrelationIdProvider;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Support\Integration\Transport\LaravelHttpTransport;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * @phpstan:ignore core.providerStatusLeak
 */

/**
 * Contract tests for `MyCoolPayAdapter` — uses `Http::fake()` to assert
 * the exact wire-level shape of every call without hitting the network.
 *
 * The adapter is wired with the real `ExternalApiCallerImpl` so we
 * exercise the full pipeline (auth skip, idempotency, signature,
 * redaction) on top of the HTTP transport fake.
 */
final class MyCoolPayAdapterTest extends TestCase
{
    private MyCoolPayAdapter $adapter;

    // ─────────────────────────────────────────────────────────────────────
    // initiate() — Payin flow
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function initiate_with_payin_flow_posts_to_payin_endpoint(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-abc',
                'action' => 'PENDING',
            ], 201),
        ]);

        $command = new InitiatePaymentCommand(
            projectId: 'project-1',
            amount: 100,
            currency: 'XAF',
            reason: 'Order #42',
            appTransactionRef: 'order-42',
            customerPhoneNumber: '+237699009900',
            customerEmail: 'alice@example.com',
        );
        $receipt = $this->adapter->initiate($command);

        self::assertSame('tx-abc', $receipt->transactionRef);
        self::assertSame(PaymentStatus::Pending, $receipt->status);
        self::assertFalse($receipt->requiresAction);
        self::assertNull($receipt->paymentUrl);
        self::assertSame('PENDING', $receipt->actionHint);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://my-coolpay.com/api/pub-123/payin'
                && $request['transaction_amount'] === 100
                && $request['transaction_currency'] === 'XAF'
                && $request['transaction_reason'] === 'Order #42'
                && $request['app_transaction_ref'] === 'order-42'
                && $request['customer_phone_number'] === '+237699009900'
                && $request['customer_email'] === 'alice@example.com';
        });
    }

    #[Test]
    public function initiate_with_payin_and_a_ussd_fallback_surfaces_the_dial_code_as_the_action_hint(): void
    {
        // Live-captured shape (review finding): MyCoolPay can respond
        // `action: "PENDING"` — which alone looks like "nothing for the
        // customer to do" — while also carrying a `ussd` fallback dial
        // code for when the push notification never reaches their phone.
        Http::fake([
            'my-coolpay.com/api/pub-123/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-ussd',
                'action' => 'PENDING',
                'ussd' => '#150*50#',
            ], 201),
        ]);

        $command = new InitiatePaymentCommand(
            projectId: 'project-1',
            amount: 100,
            currency: 'XAF',
            reason: 'r',
            appTransactionRef: 'o',
        );
        $receipt = $this->adapter->initiate($command);

        self::assertSame(PaymentStatus::Pending, $receipt->status);
        self::assertTrue($receipt->requiresAction);
        self::assertSame('#150*50#', $receipt->actionHint);
    }

    #[Test]
    public function initiate_with_payin_and_require_otp_sets_requires_action(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-otp',
                'action' => 'REQUIRE_OTP',
            ], 201),
        ]);

        $command = new InitiatePaymentCommand(
            projectId: 'project-1',
            amount: 100,
            currency: 'XAF',
            reason: 'r',
            appTransactionRef: 'o',
        );
        $receipt = $this->adapter->initiate($command);

        self::assertSame(PaymentStatus::Pending, $receipt->status);
        self::assertTrue($receipt->requiresAction);
        self::assertSame('REQUIRE_OTP', $receipt->actionHint);
    }

    // ─────────────────────────────────────────────────────────────────────
    // initiate() — Paylink flow
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function initiate_with_paylink_flow_posts_to_paylink_endpoint_and_returns_url(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/paylink' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-link',
                'payment_url' => 'https://my-coolpay.com/pay/abc',
            ], 201),
        ]);

        $command = new InitiatePaymentCommand(
            projectId: 'project-1',
            amount: 500,
            currency: 'XAF',
            reason: 'Order #43',
            appTransactionRef: 'order-43',
            flow: PaymentFlow::Paylink,
        );
        $receipt = $this->adapter->initiate($command);

        self::assertSame('tx-link', $receipt->transactionRef);
        self::assertSame('https://my-coolpay.com/pay/abc', $receipt->paymentUrl);
        self::assertSame(PaymentStatus::Pending, $receipt->status);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://my-coolpay.com/api/pub-123/paylink');
    }

    // ─────────────────────────────────────────────────────────────────────
    // submitAction() — OTP submit
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function submit_action_posts_to_authorize_with_transaction_ref_and_code(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/payin/authorize' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-otp',
                'action' => 'PENDING',
            ], 201),
        ]);

        $receipt = $this->adapter->submitAction(new PaymentAction('project-1', 'tx-otp', '1234'));

        self::assertSame('tx-otp', $receipt->transactionRef);
        self::assertSame(PaymentStatus::Pending, $receipt->status);

        Http::assertSent(function (Request $r): bool {
            return $r->method() === 'POST'
                && $r['transaction_ref'] === 'tx-otp'
                && $r['code'] === '1234';
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // checkStatus()
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function check_status_gets_the_status_of_a_known_transaction(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/checkStatus/tx-settled' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-settled',
                'transaction_status' => 'SUCCESS',
            ], 200),
        ]);

        $receipt = $this->adapter->checkStatus('tx-settled', 'project-1');

        self::assertSame(PaymentStatus::Succeeded, $receipt->status);
        self::assertTrue($receipt->isTerminal());
        self::assertFalse($receipt->requiresAction);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://my-coolpay.com/api/pub-123/checkStatus/tx-settled');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotency
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function initiate_sends_idempotency_key_matching_app_transaction_ref(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-123/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx',
                'action' => 'PENDING',
            ], 201),
        ]);

        $command = new InitiatePaymentCommand(
            projectId: 'project-1',
            amount: 100,
            currency: 'XAF',
            reason: 'r',
            appTransactionRef: 'order-cancel-safe-id-42',
        );
        $this->adapter->initiate($command);

        Http::assertSent(function (Request $r): bool {
            return $r->method() === 'POST'
                && $r->header('Idempotency-Key') === ['order-cancel-safe-id-42']
                && $r->data()['app_transaction_ref'] === 'order-cancel-safe-id-42';
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Error classification
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_4xx_response_is_a_provider_permanent_error(): void
    {
        Http::fake([
            'my-coolpay.com/*' => Http::response(['status' => 'failed', 'message' => 'bad input'], 422),
        ]);

        $this->expectException(\Core\Support\Integration\Exception\ProviderPermanentError::class);
        $this->adapter->checkStatus('whatever', 'project-1');
    }

    #[Test]
    public function a_5xx_response_is_a_transient_error(): void
    {
        Http::fake([
            'my-coolpay.com/*' => Http::response(['status' => 'failed'], 503),
        ]);

        $this->expectException(\Core\Support\Integration\Exception\ProviderTransientError::class);
        $this->adapter->checkStatus('whatever', 'project-1');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Configuration
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function initiate_throws_when_no_application_is_registered_for_the_project(): void
    {
        // Security review finding, user-directed expansion: each Project
        // has its own MyCoolPay application (public/private key pair) —
        // there is no platform-wide fallback key anymore.
        $adapter = $this->makeAdapter($this->makeConfig(), applications: []);

        $this->expectException(ProviderApplicationNotFound::class);

        $adapter->initiate(new InitiatePaymentCommand(
            projectId: 'project-without-an-application',
            amount: 1,
            currency: 'XAF',
            reason: 'r',
            appTransactionRef: 'o',
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->makeConfig();
        $this->adapter = $this->makeAdapter($config);
    }

    private function makeConfig(): ProviderConfig
    {
        return ProviderConfig::fromArray([
            'code' => 'my-coolpay',
            'base_url' => 'https://my-coolpay.com',
            'auth' => [
                'kind' => 'api-key',
                'api_key_header' => 'X-PRIVATE-KEY',
            ],
            'credentials' => ['api_key' => 'private-key'],
            'timeouts' => ['connect' => 2.0, 'request' => 10.0, 'total' => 20.0],
            'retries' => ['max_attempts' => 1, 'base_delay_ms' => 10, 'max_delay_ms' => 50],
            'breaker' => ['failure_threshold' => 5, 'open_duration_seconds' => 60],
            'enabled' => true,
            'extras' => [],
        ]);
    }

    /**
     * @param list<ProviderApplication>|null $applications Defaults to a
     *                                                     single application for 'project-1' with public key 'pub-123' — matches
     *                                                     every fixture URL above. Pass an empty array to simulate a Project
     *                                                     with no registered application.
     */
    private function makeAdapter(ProviderConfig $config, ?array $applications = null): MyCoolPayAdapter
    {
        $factory = new HttpFactory;
        Http::swap($factory);

        $clock = new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-15T10:30:00Z');
            }
        };

        $transport = new LaravelHttpTransport($factory, $clock);

        $caller = new ExternalApiCallerImpl(
            transport: $transport,
            circuitBreaker: new InMemoryCircuitBreaker($clock),
            signer: new HmacSha256RequestSigner,
            redactor: new PayloadRedactor,
            clock: $clock,
            logger: new NullLogger,
            authProviders: [
                new ApiKeyAuthProvider,
                new BasicAuthProvider,
                new BearerAuthProvider,
            ],
            correlationIds: new LaravelContextCorrelationIdProvider,
        );

        $applications ??= [new ProviderApplication('project-1', 'my-coolpay', 'pub-123', 'private-key')];

        return new MyCoolPayAdapter(
            caller: $caller,
            config: $config,
            statusMapper: new MyCoolPayStatusMapper($config->code),
            applications: new InMemoryProviderApplicationRepository($applications),
        );
    }
}
