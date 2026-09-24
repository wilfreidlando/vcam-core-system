<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Integration\Infrastructure\Provider\Waha;

use Core\Notification\Contracts\CallbackPayload;
use Core\Notification\Contracts\MissingRecipientPhoneNumber;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Infrastructure\Provider\Waha\WahaAdapter;
use Core\Notification\Infrastructure\Provider\Waha\WahaStatusMapper;
use Core\Notification\NotificationStatus;
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
 * Contract tests for `WahaAdapter` — `Http::fake()`, real
 * `ExternalApiCallerImpl` pipeline, mirrors `MyCoolPayAdapterTest`.
 */
final class WahaAdapterTest extends TestCase
{
    private WahaAdapter $adapter;

    #[Test]
    public function send_with_a_text_body_posts_to_send_text_with_rendered_variables(): void
    {
        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201),
        ]);

        $result = $this->adapter->send($this->message(
            body: ['type' => 'text', 'text' => 'Hello {{name}}, your order {{order}} is ready.'],
            variables: ['phone_number' => '+237699009900', 'name' => 'Alice', 'order' => '42'],
        ));

        self::assertSame('msg-1', $result->providerMessageId);
        self::assertSame(NotificationStatus::Sent, $result->status);

        Http::assertSent(function (Request $r): bool {
            return $r->url() === 'https://waha.test/api/sendText'
                && $r['session'] === 'project-1_shop-1'
                && $r['chatId'] === '237699009900@c.us'
                && $r['text'] === 'Hello Alice, your order 42 is ready.';
        });
    }

    #[Test]
    public function send_without_a_session_throws_instead_of_using_a_shared_one(): void
    {
        Http::fake();

        $this->expectException(\Core\Notification\Contracts\MissingChannelSession::class);

        $message = new OutboundMessage(
            appMessageRef: 'app-msg-1',
            channel: NotificationChannel::fromString('whatsapp'),
            recipientRef: 'party-ref-1',
            variables: ['phone_number' => '+237699009900'],
            body: ['type' => 'text', 'text' => 'Hi'],
            projectId: 'project-1',
        );

        try {
            $this->adapter->send($message);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function send_with_an_image_body_posts_to_send_image(): void
    {
        Http::fake([
            'waha.test/api/sendImage' => Http::response(['id' => 'msg-2'], 201),
        ]);

        $this->adapter->send($this->message(
            body: [
                'type' => 'image',
                'caption' => 'Receipt {{ref}}',
                'media_url' => 'https://files.test/r.jpg',
                'filename' => 'receipt.jpg',
                'mimetype' => 'image/jpeg',
            ],
            variables: ['phone_number' => '237699009900', 'ref' => 'R-9'],
        ));

        Http::assertSent(function (Request $r): bool {
            $file = $r['file'];

            return $r->url() === 'https://waha.test/api/sendImage'
                && $r['caption'] === 'Receipt R-9'
                && is_array($file)
                && ($file['url'] ?? null) === 'https://files.test/r.jpg'
                && ($file['mimetype'] ?? null) === 'image/jpeg';
        });
    }

    #[Test]
    public function send_uses_the_provider_side_session_name_exactly_as_the_core_resolved_it(): void
    {
        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'msg-3'], 201),
        ]);

        // Which session a message may use is decided by the Core's own
        // registry (NotificationSendCascade: a Project's session arrives
        // already namespaced, a global one under its plain name) — the
        // adapter forwards the name verbatim and never rewrites it.
        foreach (['project-1_shop-1', 'shared'] as $name) {
            $this->adapter->send($this->message(
                body: ['type' => 'text', 'text' => 'hi'],
                variables: ['phone_number' => '237699009900', 'session' => $name],
            ));
        }

        $sessions = collect(Http::recorded())->map(fn (array $pair) => $pair[0]['session'] ?? null)->all();

        self::assertSame(['project-1_shop-1', 'shared'], $sessions);
    }

    #[Test]
    public function a_send_from_a_session_waiting_for_its_qr_scan_says_so_explicitly(): void
    {
        Http::fake(['waha.test/api/sendText' => Http::response([
            'error' => 'Session status is not as expected. Try again later or restart the session',
            'session' => 'project-1_shop-1', 'status' => 'SCAN_QR_CODE', 'expected' => ['WORKING'],
        ], 422)]);

        try {
            $this->adapter->send($this->message(body: ['type' => 'text', 'text' => 'hi'], variables: ['phone_number' => '237699009900']));
            self::fail('expected ChannelSessionNotLinked');
        } catch (\Core\Notification\Contracts\ChannelSessionNotLinked $e) {
            self::assertSame('awaiting_link', $e->state);
            self::assertStringContainsString('waiting for the QR code to be scanned', $e->getMessage());
            // The provider-side name (which embeds the project id) never reaches the client.
            self::assertStringNotContainsString('project-1_shop-1', $e->getMessage());
        }
    }

    #[Test]
    public function a_send_from_a_failed_session_says_it_is_disconnected_and_any_other_422_stays_a_provider_error(): void
    {
        Http::fake(['waha.test/api/sendText' => Http::sequence()
            ->push(['error' => 'Session status is not as expected.', 'status' => 'FAILED'], 422)
            ->push(['error' => 'something else entirely'], 422)]);

        try {
            $this->adapter->send($this->message(body: ['type' => 'text', 'text' => 'hi'], variables: ['phone_number' => '237699009900']));
            self::fail('expected ChannelSessionNotLinked');
        } catch (\Core\Notification\Contracts\ChannelSessionNotLinked $e) {
            self::assertSame('disconnected', $e->state);
        }

        $this->expectException(\Core\Support\Integration\Exception\ProviderPermanentError::class);
        $this->adapter->send($this->message(body: ['type' => 'text', 'text' => 'hi'], variables: ['phone_number' => '237699009900']));
    }

    #[Test]
    public function delete_session_calls_delete_and_treats_an_already_missing_session_as_done(): void
    {
        Http::fake(['waha.test/api/sessions/*' => Http::sequence()
            ->push('', 200)
            ->push(['message' => 'not found'], 404)]);

        $this->adapter->deleteSession('project-1_shop-1');
        $this->adapter->deleteSession('project-1_gone');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && $r->url() === 'https://waha.test/api/sessions/project-1_shop-1');
        Http::assertSentCount(2);
    }

    #[Test]
    public function delete_session_does_not_swallow_a_provider_outage(): void
    {
        Http::fake(['waha.test/api/sessions/*' => Http::response('boom', 503)]);

        $this->expectException(\Core\Support\Integration\Exception\ProviderTransientError::class);
        $this->adapter->deleteSession('project-1_shop-1');
    }

    #[Test]
    public function send_without_a_phone_number_variable_throws(): void
    {
        $this->expectException(MissingRecipientPhoneNumber::class);

        $this->adapter->send($this->message(
            body: ['type' => 'text', 'text' => 'hi'],
            variables: [],
        ));
    }

    #[Test]
    public function start_session_posts_to_sessions_with_the_session_ref(): void
    {
        Http::fake(['waha.test/api/sessions' => Http::response(['name' => 'shop-1'], 201)]);

        $this->adapter->startSession('shop-1');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waha.test/api/sessions'
            && $r['name'] === 'shop-1'
            && $r['start'] === true);
    }

    #[Test]
    public function start_session_tolerates_a_session_already_existing(): void
    {
        Http::fake(['waha.test/api/sessions' => Http::response(['message' => 'already exists'], 422)]);

        // No exception — the desired end state is already satisfied.
        $this->adapter->startSession('shop-1');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waha.test/api/sessions');
    }

    #[Test]
    public function request_pairing_code_returns_the_code(): void
    {
        Http::fake(['waha.test/api/shop-1/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200)]);

        $code = $this->adapter->requestPairingCode('shop-1', '237699009900');

        self::assertSame('ABCD-ABCD', $code);
        Http::assertSent(fn (Request $r): bool => $r['phoneNumber'] === '237699009900');
    }

    #[Test]
    public function get_qr_code_base64_encodes_the_raw_response_body(): void
    {
        Http::fake([
            'waha.test/api/shop-1/auth/qr' => Http::response('binary-qr-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $qr = $this->adapter->getQrCode('shop-1');

        self::assertSame('image/png', $qr->mimeType);
        self::assertSame(base64_encode('binary-qr-bytes'), $qr->base64Data);
    }

    #[Test]
    public function get_screenshot_passes_session_as_a_query_parameter(): void
    {
        Http::fake([
            'waha.test/api/screenshot*' => Http::response('binary-png', 200, ['Content-Type' => 'image/png']),
        ]);

        $screenshot = $this->adapter->getScreenshot('shop-1');

        self::assertSame(base64_encode('binary-png'), $screenshot->base64Data);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'session=shop-1'));
    }

    #[Test]
    public function an_unsupported_screenshot_surfaces_the_providers_own_explanation(): void
    {
        Http::fake([
            'waha.test/api/screenshot*' => Http::response(
                ['message' => 'Can not get screenshot for non chrome based engine.', 'statusCode' => 422],
                422,
            ),
        ]);

        try {
            $this->adapter->getScreenshot('shop-1');
            self::fail('Expected a provider error.');
        } catch (\Core\Support\Integration\Exception\ProviderPermanentError $e) {
            self::assertStringContainsString('non chrome based engine', $e->getMessage());
        }
    }

    #[Test]
    public function get_session_state_maps_the_providers_states_to_canonical_ones(): void
    {
        $expected = [
            'WORKING' => \Core\Notification\Contracts\ChannelSessionState::Linked,
            'SCAN_QR_CODE' => \Core\Notification\Contracts\ChannelSessionState::AwaitingLink,
            'STARTING' => \Core\Notification\Contracts\ChannelSessionState::AwaitingLink,
            'STOPPED' => \Core\Notification\Contracts\ChannelSessionState::Stopped,
            'FAILED' => \Core\Notification\Contracts\ChannelSessionState::Failed,
            'SOMETHING_NEW' => \Core\Notification\Contracts\ChannelSessionState::Unknown,
        ];

        $sequence = Http::sequence();
        foreach (array_keys($expected) as $raw) {
            $sequence->push(['name' => 'shop-1', 'status' => $raw], 200);
        }
        Http::fake(['waha.test/api/sessions/shop-1' => $sequence]);

        foreach ($expected as $raw => $state) {
            self::assertSame($state, $this->adapter->getSessionState('shop-1'), $raw);
        }
    }

    #[Test]
    public function get_session_state_of_a_session_the_provider_does_not_know_is_missing_not_an_error(): void
    {
        Http::fake(['waha.test/api/sessions/ghost' => Http::response(['message' => 'Session not found'], 404)]);

        self::assertSame(\Core\Notification\Contracts\ChannelSessionState::MissingAtProvider, $this->adapter->getSessionState('ghost'));
    }

    #[Test]
    public function handle_delivery_callback_maps_the_ack_code(): void
    {
        $status = $this->adapter->handleDeliveryCallback(new CallbackPayload(
            raw: ['event' => 'message.ack', 'payload' => ['id' => 'msg-1', 'ack' => 2]],
            headers: [],
        ));

        self::assertSame(NotificationStatus::Delivered, $status->status);
    }

    #[Test]
    public function supports_only_the_whatsapp_channel(): void
    {
        self::assertTrue($this->adapter->supports(NotificationChannel::fromString('whatsapp'), 'CM'));
        self::assertFalse($this->adapter->supports(NotificationChannel::fromString('sms'), 'CM'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->makeConfig();
        $this->adapter = $this->makeAdapter($config);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $variables
     */
    private function message(array $body, array $variables, string $projectId = 'project-1'): OutboundMessage
    {
        return new OutboundMessage(
            appMessageRef: 'app-msg-1',
            channel: NotificationChannel::fromString('whatsapp'),
            recipientRef: 'party-ref-1',
            variables: $variables + ['session' => 'project-1_shop-1'],
            body: $body,
            projectId: $projectId,
        );
    }

    private function makeConfig(): ProviderConfig
    {
        return ProviderConfig::fromArray([
            'code' => 'whatsapp-waha',
            'base_url' => 'https://waha.test',
            'auth' => ['kind' => 'api-key', 'api_key_header' => 'X-Api-Key'],
            'credentials' => ['api_key' => 'waha-key'],
            'timeouts' => ['connect' => 2.0, 'request' => 10.0, 'total' => 20.0],
            'retries' => ['max_attempts' => 1, 'base_delay_ms' => 10, 'max_delay_ms' => 50],
            'breaker' => ['failure_threshold' => 5, 'open_duration_seconds' => 60],
            'enabled' => true,
            'extras' => [],
        ]);
    }

    private function makeAdapter(ProviderConfig $config): WahaAdapter
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

        return new WahaAdapter(
            caller: $caller,
            config: $config,
            statusMapper: new WahaStatusMapper($config->code),
        );
    }
}
