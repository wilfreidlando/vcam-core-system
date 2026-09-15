<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Provider\Waha;

use Core\Notification\Contracts\CallbackPayload;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionQrCode;
use Core\Notification\Contracts\ChannelSessionScreenshot;
use Core\Notification\Contracts\DeliveryStatus;
use Core\Notification\Contracts\MissingRecipientPhoneNumber;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProvider;
use Core\Notification\Contracts\NotificationStatusMapper;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Contracts\ProviderDispatchResult;
use Core\Notification\NotificationStatus;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\ExternalApiCaller;
use Core\Support\Integration\ExternalRequest;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * WhatsApp adapter for a self-hosted WAHA (WhatsApp HTTP API) instance
 * (ADR-0042). Implements two independent contracts on one class:
 *
 *   - `NotificationChannelProvider` — send text/image/file, the
 *     universal channel-provider surface every adapter has.
 *   - `ChannelSessionProvider` — link a phone (QR / pairing code /
 *     screenshot), a WAHA-specific capability most channel providers
 *     don't have (an SMS gateway has no "session" to link).
 *
 * # Anti-corruption boundary
 *
 * This is the only class that knows WAHA's URL scheme, request/response
 * shapes and `ack` vocabulary (G6). Message content is entirely
 * interpreted here too: `OutboundMessage::body` is the template's raw
 * `{"type": "text"|"image"|"file", ...}` payload (ADR-0042 §6) — the
 * Core does not know what a "WhatsApp message" looks like, only this
 * adapter does.
 *
 * # Recipient addressing
 *
 * `OutboundMessage::recipientRef` is a pure correlation id (G4). The
 * real destination number MUST arrive as `variables['phone_number']`
 * (ADR-0042 §7) — never persisted by the Core, since it never reaches
 * any Core table, only this adapter's in-memory call.
 *
 * # Session addressing
 *
 * The Core persists nothing about a WAHA session (ADR-0042 §4). For
 * `send()`, the session to send from is `variables['session']` if the
 * caller supplied one, falling back to `extras.default_session`
 * (config), falling back to WAHA's own default session name `"default"`
 * — so a Project with exactly one linked number needs to pass neither.
 */
final readonly class WahaAdapter implements ChannelSessionProvider, NotificationChannelProvider
{
    private const DEFAULT_SESSION = 'default';

    public function __construct(
        private ExternalApiCaller $caller,
        private ProviderConfig $config,
        private NotificationStatusMapper $statusMapper,
    ) {}

    public function providerCode(): ProviderCode
    {
        return $this->config->code;
    }

    public function config(): ProviderConfig
    {
        return $this->config;
    }

    // -----------------------------------------------------------------
    // NotificationChannelProvider
    // -----------------------------------------------------------------

    public function send(OutboundMessage $message): ProviderDispatchResult
    {
        $phoneNumber = self::str($message->variables, 'phone_number');
        if ($phoneNumber === '') {
            throw MissingRecipientPhoneNumber::forMessage($message->appMessageRef);
        }

        $chatId = self::toChatId($phoneNumber);
        $sessionRef = self::sessionRefFor($message->variables, $this->config);
        $type = self::str($message->body, 'type', 'text');

        $response = match ($type) {
            'image' => $this->doSendImage($sessionRef, $chatId, $message->body, $message->variables),
            'file' => $this->doSendFile($sessionRef, $chatId, $message->body, $message->variables),
            default => $this->doSendText($sessionRef, $chatId, $message->body, $message->variables),
        };

        $providerMessageId = self::messageIdFrom($response->body ?? []);
        if ($providerMessageId === '') {
            throw new RuntimeException('WAHA send response is missing a message id.');
        }

        return new ProviderDispatchResult($providerMessageId, NotificationStatus::Sent, null);
    }

    public function getStatus(string $providerMessageId): DeliveryStatus
    {
        // WAHA (built on the WhatsApp Web protocol) is push-only for
        // delivery status — it reports `ack` changes via the
        // `message.ack` webhook, there is no reliable poll-by-id
        // endpoint across engines. `HandleNotificationStatusChangedUseCase`
        // is the intended path (see the inbound webhook controller).
        throw new RuntimeException(
            'WahaAdapter::getStatus() is not supported — WAHA reports delivery status via the message.ack webhook, not by polling.',
        );
    }

    public function handleDeliveryCallback(CallbackPayload $payload): DeliveryStatus
    {
        $ackPayload = $payload->raw['payload'] ?? [];
        $ack = is_array($ackPayload) ? ($ackPayload['ack'] ?? null) : null;

        if (! is_int($ack) && ! is_string($ack)) {
            throw new RuntimeException('WAHA callback payload is missing payload.ack.');
        }

        return new DeliveryStatus($this->statusMapper->map((string) $ack));
    }

    public function supports(NotificationChannel $channel, string $country): bool
    {
        return $channel->value === 'whatsapp';
    }

    // -----------------------------------------------------------------
    // ChannelSessionProvider
    // -----------------------------------------------------------------

    public function startSession(string $sessionRef): void
    {
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/api/sessions',
            providerCode: $this->config->code,
            body: ['name' => $sessionRef, 'start' => true],
            operation: 'whatsapp-waha.session.start',
        );

        try {
            $this->caller->send($this->config, $request);
        } catch (ProviderPermanentError) {
            // WAHA answers with a 4xx when a session by this name
            // already exists — the desired end state (present, starting
            // or already running) is already satisfied.
        }
    }

    public function requestPairingCode(string $sessionRef, string $phoneNumber): string
    {
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/api/'.$sessionRef.'/auth/request-code',
            providerCode: $this->config->code,
            body: ['phoneNumber' => $phoneNumber],
            operation: 'whatsapp-waha.session.request-code',
        );

        $response = $this->caller->send($this->config, $request);
        $code = self::str($response->body ?? [], 'code');
        if ($code === '') {
            throw new RuntimeException('WAHA request-code response is missing "code".');
        }

        return $code;
    }

    public function getQrCode(string $sessionRef): ChannelSessionQrCode
    {
        $request = new ExternalRequest(
            method: HttpMethod::GET,
            path: '/api/'.$sessionRef.'/auth/qr',
            providerCode: $this->config->code,
            operation: 'whatsapp-waha.session.qr',
        );

        $response = $this->caller->send($this->config, $request);

        return new ChannelSessionQrCode(
            mimeType: self::contentTypeOf($response),
            base64Data: base64_encode($response->rawBody),
        );
    }

    public function getScreenshot(string $sessionRef): ChannelSessionScreenshot
    {
        $request = new ExternalRequest(
            method: HttpMethod::GET,
            path: '/api/screenshot',
            providerCode: $this->config->code,
            query: ['session' => $sessionRef],
            operation: 'whatsapp-waha.session.screenshot',
        );

        $response = $this->caller->send($this->config, $request);

        return new ChannelSessionScreenshot(
            mimeType: self::contentTypeOf($response),
            base64Data: base64_encode($response->rawBody),
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $body The template's raw body — see class docblock.
     * @param array<string, mixed> $variables
     */
    private function doSendText(string $sessionRef, string $chatId, array $body, array $variables): ExternalResponse
    {
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/api/sendText',
            providerCode: $this->config->code,
            body: [
                'session' => $sessionRef,
                'chatId' => $chatId,
                'text' => self::render(self::str($body, 'text'), $variables),
            ],
            operation: 'whatsapp-waha.send-text',
        );

        return $this->caller->send($this->config, $request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $variables
     */
    private function doSendImage(string $sessionRef, string $chatId, array $body, array $variables): ExternalResponse
    {
        return $this->doSendMedia('/api/sendImage', 'whatsapp-waha.send-image', $sessionRef, $chatId, $body, $variables);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $variables
     */
    private function doSendFile(string $sessionRef, string $chatId, array $body, array $variables): ExternalResponse
    {
        return $this->doSendMedia('/api/sendFile', 'whatsapp-waha.send-file', $sessionRef, $chatId, $body, $variables);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $variables
     */
    private function doSendMedia(string $path, string $operation, string $sessionRef, string $chatId, array $body, array $variables): ExternalResponse
    {
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: $path,
            providerCode: $this->config->code,
            body: [
                'session' => $sessionRef,
                'chatId' => $chatId,
                'file' => [
                    'mimetype' => self::str($body, 'mimetype'),
                    'url' => self::str($body, 'media_url'),
                    'filename' => self::str($body, 'filename'),
                ],
                'caption' => self::render(self::str($body, 'caption'), $variables),
            ],
            operation: $operation,
        );

        return $this->caller->send($this->config, $request);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function sessionRefFor(array $variables, ProviderConfig $config): string
    {
        $fromVariables = self::str($variables, 'session');
        if ($fromVariables !== '') {
            return $fromVariables;
        }

        $default = $config->extra('default_session', self::DEFAULT_SESSION);

        return is_string($default) && $default !== '' ? $default : self::DEFAULT_SESSION;
    }

    /**
     * WAHA addresses individual contacts as `<digits-only phone>@c.us`.
     */
    private static function toChatId(string $phoneNumber): string
    {
        return preg_replace('/\D/', '', $phoneNumber).'@c.us';
    }

    /**
     * `{{variable}}` interpolation — the only templating WAHA content
     * needs; anything richer belongs to a future templating capability,
     * not this adapter.
     *
     * @param array<string, mixed> $variables
     */
    private static function render(string $template, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $replacements['{{'.$key.'}}'] = (string) $value;
            }
        }

        return strtr($template, $replacements);
    }

    private static function contentTypeOf(ExternalResponse $response): string
    {
        $raw = $response->headers['content-type'] ?? 'image/png';
        $withoutParams = explode(';', $raw, 2)[0];

        return trim($withoutParams) !== '' ? trim($withoutParams) : 'image/png';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * WAHA's sendText/sendImage/sendFile response nests the message id
     * under `key.id` (the underlying WhatsApp Web protocol's message
     * key), not a flat top-level `id` — confirmed against a real WAHA
     * instance's response shape.
     *
     * @param array<string, mixed> $body
     */
    private static function messageIdFrom(array $body): string
    {
        $flat = self::str($body, 'id');
        if ($flat !== '') {
            return $flat;
        }

        $key = $body['key'] ?? null;
        $nestedId = is_array($key) ? ($key['id'] ?? null) : null;

        return is_string($nestedId) ? $nestedId : '';
    }
}
