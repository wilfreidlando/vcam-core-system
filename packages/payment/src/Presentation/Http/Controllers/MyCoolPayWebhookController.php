<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\Domain\Port\PaymentCallbackAuthenticator;
use Core\Support\Clock;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Configuration\ProviderNotConfigured;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Receives MyCoolPay's transaction-status webhook, translates it into a
 * canonical `PaymentStatusChanged`, and hands it to
 * `PaymentStatusChangeHandler` — which resolves the `Payment` this
 * refers to, updates its state, credits a wallet if (and only if) the
 * caller asked for one at initiation (ADR-0037), and publishes the
 * event to the outbox, all in one transaction (ADR-0036, ADR-0038 §4/§5).
 *
 * # Authenticity (security review finding — this used to be entirely
 * unauthenticated)
 *
 * Two checks, per MyCoolPay's own documented procedure (API docs §7.5
 * "Callback requests integrity"), BOTH required before anything else is
 * even considered — see `MyCoolPayCallbackAuthenticator`:
 *  1. The request's source IP must be MyCoolPay's server.
 *  2. The payload's `signature` must match
 *     `md5(transaction_ref+transaction_type+transaction_amount+
 *     transaction_currency+transaction_operator+private_key)`.
 * Either failing means the request is not from MyCoolPay: rejected with
 * 403, not silently ignored with 200 — this is the actual trust
 * boundary, not a business-logic filter.
 *
 * If the private key cannot even be loaded (provider misconfigured),
 * the webhook is likewise rejected — we would have no basis to trust it.
 *
 * # No outbound call — everything happens from the inbound payload.
 *
 * # Business filters (applied only AFTER authenticity is established)
 *
 *  1. `transaction_type` must be `'PAYIN'`. Payout webhooks are
 *     ignored here — they will be handled by a dedicated controller
 *     when payout support lands.
 *  2. `application` (the public key) must match the configured one.
 *     MyCoolPay's own docs confirm this key is NOT secret — it's shown
 *     in every checkout link — so it proves nothing about the caller by
 *     itself; kept only as a secondary cross-tenant sanity check now
 *     that the signature above is the real authenticity gate.
 * Both reply 200 OK to keep MyCoolPay from retrying — but log a
 * `warning` so operators see the noise.
 */
final class MyCoolPayWebhookController
{
    /**
     * Expected inbound shape from MyCoolPay (see docs §6):
     *
     *   {
     *     "application": "{public_key}",
     *     "app_transaction_ref": "...",
     *     "operator_transaction_ref": "...",
     *     "transaction_ref": "...",
     *     "transaction_type": "PAYIN" | "PAYOUT",
     *     "transaction_amount": 100,
     *     "transaction_fees": 2,
     *     "transaction_currency": "XAF",
     *     "transaction_operator": "...",
     *     "transaction_status": "SUCCESS" | "CANCELED" | "FAILED",
     *     "transaction_reason": "...",
     *     "transaction_message": "...",
     *     "customer_phone_number": "...",
     *     "signature": "..."
     *   }
     */
    public function __construct(
        private PaymentStatusChangeHandler $statusChangeHandler,
        private PaymentStatusMapper $statusMapper,
        private Clock $clock,
        private ProviderConfigRegistry $configRegistry,
        private PaymentCallbackAuthenticator $authenticator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Laravel's `$request->json()->all()` always returns an array
        // (possibly empty for a non-JSON body). The validation that
        // follows treats an empty body as "missing fields" → no handler
        // call → 200 OK so MyCoolPay does not retry forever.
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        if (! $this->isAuthentic($request, $body)) {
            // Not a business-logic filter — this is the actual trust
            // boundary. A 403 here (unlike the 200s below) is a
            // deliberate, visible signal: MyCoolPay doesn't retry
            // callbacks regardless of the response, so this costs
            // nothing on their side, and it keeps a forged or
            // misconfigured request from ever looking like a normally
            // "ignored" one in our own logs/metrics.
            return new JsonResponse(['status' => 'forbidden'], 403);
        }

        $event = $this->buildEvent($body);
        if ($event === null) {
            // The payload was authentic but the controller chose not to
            // proceed (wrong transaction type, wrong application,
            // missing fields, or unknown status). We have already logged
            // a warning. Reply 200 OK anyway: MyCoolPay considers any 2xx
            // a successful delivery; bouncing 4xx would make them retry
            // forever for a payload they will keep sending.
            return new JsonResponse(['status' => 'ignored'], 200);
        }

        $this->statusChangeHandler->handle($event);

        $this->logger->info('my-coolpay.webhook.handled', [
            'provider_code' => $event->providerCode,
            'transaction_ref' => $event->transactionRef,
            'status' => $event->status->value,
        ]);

        return new JsonResponse(['status' => 'received'], 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function isAuthentic(Request $request, array $body): bool
    {
        if (! $this->authenticator->isFromTrustedIp($request->ip())) {
            $this->logger->error('my-coolpay.webhook.untrusted_ip', [
                'ip' => $request->ip(),
            ]);

            return false;
        }

        try {
            $privateKey = $this->configRegistry->configFor(
                ProviderCode::fromString('my-coolpay'),
            )->defaultCredentials->apiKey;
        } catch (ProviderNotConfigured) {
            $privateKey = null;
        }

        if ($privateKey === null || $privateKey === '') {
            // No key means no way to verify — never fall back to
            // trusting the payload.
            $this->logger->error('my-coolpay.webhook.no_private_key_configured');

            return false;
        }

        if (! $this->authenticator->hasValidSignature($body, $privateKey)) {
            $this->logger->error('my-coolpay.webhook.invalid_signature', [
                'transaction_ref' => $body['transaction_ref'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildEvent(array $body): ?PaymentStatusChanged
    {
        // Filter 1: only PAYIN events. PAYOUT will have its own controller.
        $type = self::stringField($body, 'transaction_type');
        if ($type !== 'PAYIN') {
            $this->logger->warning('my-coolpay.webhook.unsupported_type', [
                'transaction_type' => $type === '' ? null : $type,
            ]);

            return null;
        }

        // Filter 2: the public key in the payload must match the one this
        // controller is bound to. Prevents cross-tenant event injection.
        $application = self::stringField($body, 'application');
        $expectedKey = $this->expectedPublicKey();
        if ($expectedKey !== null && $application !== '' && $application !== $expectedKey) {
            $this->logger->warning('my-coolpay.webhook.application_mismatch', [
                'expected_prefix' => substr($expectedKey, 0, 8).'...',
                'got_prefix' => substr($application, 0, 8).'...',
            ]);

            return null;
        }

        $transactionRef = self::stringField($body, 'transaction_ref');
        $appTransactionRef = self::stringField($body, 'app_transaction_ref');
        $rawStatus = self::stringField($body, 'transaction_status');
        $reasonRaw = $body['transaction_reason'] ?? null;
        $reason = is_string($reasonRaw) ? $reasonRaw : null;

        if ($transactionRef === '' || $rawStatus === '') {
            $this->logger->warning('my-coolpay.webhook.missing_fields', [
                'has_transaction_ref' => $transactionRef !== '',
                'has_status' => $rawStatus !== '',
            ]);

            return null;
        }

        try {
            $status = $this->statusMapper->map($rawStatus);
        } catch (\Core\Support\Integration\Exception\UnknownProviderStatusException) {
            // Status vocabulary drift. Log and skip — refusing the
            // webhook would make MyCoolPay retry the same payload forever.
            $this->logger->warning('my-coolpay.webhook.unknown_status', [
                'raw_status' => $rawStatus,
                'transaction_ref' => $transactionRef,
            ]);

            return null;
        }

        // `occurredAt` would be the provider's reported timestamp; MyCoolPay
        // doesn't include one in the webhook, so we use the receive time.
        $occurredAt = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        return new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $transactionRef,
            appTransactionRef: $appTransactionRef,
            status: $status,
            reason: $reason,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Return the public_key configured for our MyCoolPay application, or
     * null if the config registry doesn't know about it. Returning null
     * means "no application key check possible" — the webhook is
     * accepted on that point but logged for review.
     */
    private function expectedPublicKey(): ?string
    {
        try {
            $config = $this->configRegistry->configFor(
                ProviderCode::fromString('my-coolpay'),
            );
        } catch (ProviderNotConfigured) {
            return null;
        }
        $value = $config->extra('public_key', '');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
