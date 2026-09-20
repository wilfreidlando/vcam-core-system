<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Delivery;

use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentCallbackSendOutcome;
use Core\Payment\Domain\Port\PaymentCallbackSender;
use Core\Support\Clock;
use Core\Support\Integration\Configuration\SignatureAlgorithm;
use Core\Support\Integration\Configuration\SignaturePolicy;
use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Integration\Transport\HttpTransport;
use JsonException;

/**
 * Delivers a `payment.status_changed` notification to a Payment's own
 * `callbackUrl` — the reliable, server-to-server counterpart to
 * `returnUrl` (browser redirect, may never fire). Composes the same
 * `Core\Support\Integration` primitives `Core\Webhook\Infrastructure\Delivery\HttpWebhookSender`
 * does (`HttpTransport` for the wire, `RequestSigner`+`SignaturePolicy`
 * for HMAC-SHA256), the sole permitted outbound-HTTP point (G6c) — this
 * class does not call `Http::` or curl directly.
 *
 * Deliberately NOT the `webhook` module's own delivery path: that one is
 * built around a persisted, Project-registered `WebhookEndpoint`
 * (broadcast to every subscriber of an event type); this is a one-off,
 * payment-scoped destination the caller supplied at initiation, signed
 * with a secret generated and shown once for that single payment. Mirrors
 * `HttpWebhookSender`'s outcome-value-not-exception shape for the same
 * reason: a delivery failure is an expected, retriable outcome, not a
 * `ProviderException` (which also requires a catalogued `ProviderCode`
 * this arbitrary caller-supplied URL does not have).
 *
 * Implements the `Domain\Port\PaymentCallbackSender` port (named
 * identically, different namespace) so Presentation-layer callers depend
 * on the port, never this concrete Infrastructure type directly
 * (deptrac-layers.yaml).
 */
final readonly class HttpPaymentCallbackSender implements PaymentCallbackSender
{
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpTransport $transport,
        private RequestSigner $signer,
        private Clock $clock,
    ) {}

    public function send(Payment $payment): PaymentCallbackSendOutcome
    {
        if ($payment->callbackUrl === null || $payment->callbackSecret === null) {
            return PaymentCallbackSendOutcome::noCallbackConfigured();
        }

        try {
            $body = json_encode([
                'event' => 'payment.status_changed',
                'payment_id' => $payment->id,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'error_message' => $payment->errorMessage,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return PaymentCallbackSendOutcome::failure(null, 'Payload could not be encoded as JSON: '.$e->getMessage());
        }

        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: $payment->callbackSecret,
        );

        $headers = ['Content-Type' => 'application/json'];
        $timestamp = $this->clock->now()->getTimestamp();
        $this->signer->sign($policy, $headers, $body, $timestamp);

        try {
            $response = $this->transport->send(
                method: HttpMethod::POST,
                url: $payment->callbackUrl,
                headers: $headers,
                body: $body,
                timeoutSeconds: self::TIMEOUT_SECONDS,
            );
        } catch (ProviderException $e) {
            return PaymentCallbackSendOutcome::failure($e->httpStatus, $e->getMessage());
        }

        if ($response->status >= 200 && $response->status < 300) {
            return PaymentCallbackSendOutcome::success($response->status);
        }

        return PaymentCallbackSendOutcome::failure(
            $response->status,
            sprintf('Callback endpoint responded with HTTP %d.', $response->status),
        );
    }
}
