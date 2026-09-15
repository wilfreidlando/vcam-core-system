<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Delivery;

use Core\Support\Clock;
use Core\Support\Integration\Configuration\SignatureAlgorithm;
use Core\Support\Integration\Configuration\SignaturePolicy;
use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Model\WebhookSendOutcome;
use Core\Webhook\Domain\Port\WebhookSender;
use JsonException;

/**
 * The only place in `webhook` that composes `Core\Support\Integration`
 * primitives into an actual outbound call (ADR-0040 §3): `HttpTransport`
 * for the wire, `RequestSigner` + `SignaturePolicy` (per-endpoint secret,
 * not a catalogued provider's) for the HMAC-SHA256 signature — the same
 * primitive used to verify incoming provider webhooks, applied in the
 * other direction.
 */
final readonly class HttpWebhookSender implements WebhookSender
{
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpTransport $transport,
        private RequestSigner $signer,
        private Clock $clock,
    ) {}

    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): WebhookSendOutcome
    {
        try {
            $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return WebhookSendOutcome::failure(null, 'Payload could not be encoded as JSON: '.$e->getMessage());
        }

        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: $endpoint->secret,
        );

        $headers = [
            'Content-Type' => 'application/json',
            'X-Event-Id' => $delivery->eventId,
            'X-Event-Type' => $delivery->eventType,
        ];

        $timestamp = $this->clock->now()->getTimestamp();
        $this->signer->sign($policy, $headers, $body, $timestamp);

        try {
            $response = $this->transport->send(
                method: HttpMethod::POST,
                url: $endpoint->url,
                headers: $headers,
                body: $body,
                timeoutSeconds: self::TIMEOUT_SECONDS,
            );
        } catch (ProviderException $e) {
            return WebhookSendOutcome::failure($e->httpStatus, $e->getMessage());
        }

        if ($response->status >= 200 && $response->status < 300) {
            return WebhookSendOutcome::success($response->status);
        }

        return WebhookSendOutcome::failure(
            $response->status,
            sprintf('Endpoint responded with HTTP %d.', $response->status),
        );
    }
}
