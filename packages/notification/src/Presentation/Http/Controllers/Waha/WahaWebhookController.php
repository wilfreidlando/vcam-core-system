<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers\Waha;

use Core\Notification\Contracts\Events\NotificationStatusChanged;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Contracts\NotificationStatusMapper;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderNotConfigured;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Receives WAHA's `message.ack` webhook event, translates it into a
 * canonical `NotificationStatusChanged`, and hands it to
 * `NotificationStatusChangeHandler` — mirrors `MyCoolPayWebhookController`
 * (same direct-mapper shape: parse body, map status, call the handler,
 * always reply 200 so the provider stops retrying).
 *
 * # Auth
 *
 * WAHA is configured to send a shared-secret custom header on every
 * webhook call (ADR-0042 §8 — no cryptographic signature scheme is
 * assumed here, since it isn't reliably known across WAHA versions).
 * Compared in constant time against
 * `providers.notification.whatsapp_waha.extras.webhook_secret`.
 *
 * # Expected inbound shape
 *
 *   {
 *     "event": "message.ack",
 *     "session": "...",
 *     "payload": {
 *       "id": "...",       // provider message id
 *       "ack": 1,          // -1..4, see RawWahaStatus
 *       ...
 *     }
 *   }
 *
 * Events other than `message.ack` are acknowledged and ignored.
 */
final class WahaWebhookController
{
    private const WEBHOOK_SECRET_HEADER = 'X-Webhook-Secret';

    public function __construct(
        private NotificationStatusChangeHandler $statusChangeHandler,
        private NotificationStatusMapper $statusMapper,
        private ProviderConfigRegistry $configRegistry,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            $this->logger->warning('whatsapp-waha.webhook.invalid_secret');

            return new JsonResponse(['status' => 'ignored'], 200);
        }

        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        $event = $this->buildEvent($body);
        if ($event === null) {
            return new JsonResponse(['status' => 'ignored'], 200);
        }

        $this->statusChangeHandler->handle($event);

        return new JsonResponse(['status' => 'received'], 200);
    }

    private function hasValidSecret(Request $request): bool
    {
        $expected = $this->configuredWebhookSecret();
        if ($expected === null) {
            // No secret configured — refuse to accept unverified traffic
            // rather than silently trusting everything.
            return false;
        }

        $given = $request->header(self::WEBHOOK_SECRET_HEADER, '');

        return hash_equals($expected, $given);
    }

    private function configuredWebhookSecret(): ?string
    {
        try {
            $config = $this->configRegistry->configFor(ProviderCode::fromString('whatsapp-waha'));
        } catch (ProviderNotConfigured) {
            return null;
        }

        $value = $config->extra('webhook_secret', '');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildEvent(array $body): ?NotificationStatusChanged
    {
        $eventName = $body['event'] ?? null;
        if ($eventName !== 'message.ack') {
            return null;
        }

        $payload = $body['payload'] ?? null;
        if (! is_array($payload)) {
            $this->logger->warning('whatsapp-waha.webhook.missing_payload');

            return null;
        }

        $messageId = $payload['id'] ?? null;
        $ack = $payload['ack'] ?? null;

        if (! is_string($messageId) || $messageId === '' || (! is_int($ack) && ! is_string($ack))) {
            $this->logger->warning('whatsapp-waha.webhook.missing_fields');

            return null;
        }

        try {
            $status = $this->statusMapper->map((string) $ack);
        } catch (UnknownProviderStatusException) {
            $this->logger->warning('whatsapp-waha.webhook.unknown_ack', ['ack' => $ack]);

            return null;
        }

        return new NotificationStatusChanged(
            providerCode: 'whatsapp-waha',
            providerMessageId: $messageId,
            status: $status,
        );
    }
}
