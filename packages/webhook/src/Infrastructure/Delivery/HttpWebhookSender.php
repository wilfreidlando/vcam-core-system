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

    /**
     * The receiver's response is diagnostic data shown to an operator, not
     * a value the Core acts on — bounding it keeps a misbehaving or huge
     * response from bloating `webhook_deliveries` without needing a
     * dedicated redaction pipeline (unlike `Core\Support\Integration`'s
     * provider caller, this sender deliberately doesn't go through it —
     * see its own docblock).
     */
    private const MAX_RESPONSE_BODY_LENGTH = 4096;

    public function __construct(
        private HttpTransport $transport,
        private RequestSigner $signer,
        private Clock $clock,
    ) {}

    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): WebhookSendOutcome
    {
        // The JSON body self-describes its event — `id`/`type` alongside
        // the raw event payload under `data` — not just the `X-Event-Id`/
        // `X-Event-Type` headers. Review finding: a receiver subscribed to
        // several event types on the same URL (the normal case for an
        // endpoint registered with `events: ["*"]`) had no reliable way to
        // tell them apart from the body alone — some HTTP client/framework
        // combinations make custom headers far more awkward to read than
        // the body, and different event types are not guaranteed to have
        // disjoint field names forever. This must be robust regardless of
        // how the receiver's stack surfaces headers.
        $envelope = [
            'id' => $delivery->eventId,
            'type' => $delivery->eventType,
            'data' => $delivery->payload,
        ];

        try {
            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
            // ADR-0059: identifies this delivery (a replay gets a new one);
            // deduplicate on X-Event-Id, quote X-Delivery-Id to support.
            'X-Delivery-Id' => $delivery->id,
        ];

        $timestamp = $this->clock->now()->getTimestamp();
        $this->signer->sign($policy, $headers, $body, $timestamp);

        $shownHeaders = $headers;
        $shownHeaders['X-Signature'] = '***REDACTED***';
        $startedAt = hrtime(true);

        try {
            $response = $this->transport->send(
                method: HttpMethod::POST,
                url: $endpoint->url,
                headers: $headers,
                body: $body,
                timeoutSeconds: self::TIMEOUT_SECONDS,
            );
        } catch (ProviderException $e) {
            // No response was ever received — nothing to show beyond the
            // failure message itself.
            return WebhookSendOutcome::failure($e->httpStatus, $e->getMessage())
                ->withRequest($shownHeaders, $body, self::elapsedMs($startedAt));
        }

        $elapsed = self::elapsedMs($startedAt);

        $responseBody = mb_strlen($response->rawBody) > self::MAX_RESPONSE_BODY_LENGTH
            ? mb_substr($response->rawBody, 0, self::MAX_RESPONSE_BODY_LENGTH).'… (truncated)'
            : $response->rawBody;

        if ($response->status >= 200 && $response->status < 300) {
            return WebhookSendOutcome::success($response->status, $responseBody)
                ->withRequest($shownHeaders, $body, $elapsed);
        }

        return WebhookSendOutcome::failure(
            $response->status,
            sprintf('Endpoint responded with HTTP %d.', $response->status),
            $responseBody,
        )->withRequest($shownHeaders, $body, $elapsed);
    }

    private static function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
