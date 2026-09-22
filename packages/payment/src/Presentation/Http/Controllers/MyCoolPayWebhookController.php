<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\Domain\Port\PaymentCallbackAuthenticator;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Clock;
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
 * Three checks, per MyCoolPay's own documented procedure (API docs §7.5
 * "Callback requests integrity") plus this Core's own multi-tenant model
 * (ADR-0051 — each Project has its own MyCoolPay application/key pair),
 * ALL required before anything else is even considered — see
 * `MyCoolPayCallbackAuthenticator` and `ProviderApplicationRepository`:
 *  1. The request's source IP must be MyCoolPay's server.
 *  2. The payload's `application` (public key) must resolve to a
 *     registered `ProviderApplication` — an unrecognised application has
 *     no private key to verify against and is rejected outright.
 *  3. The payload's `signature` must match
 *     `md5(transaction_ref+transaction_type+transaction_amount+
 *     transaction_currency+transaction_operator+private_key)`, using
 *     THAT application's own private key — never a shared platform-wide
 *     one. This is what actually proves the caller knows the secret for
 *     the Project it claims to be notifying, since the public key alone
 *     proves nothing (MyCoolPay's own docs confirm it isn't secret — it's
 *     shown in every checkout link).
 * Any of these failing means the request is not authentically from
 * MyCoolPay for the application it claims: rejected with 403, not
 * silently ignored with 200 — this is the actual trust boundary, not a
 * business-logic filter.
 *
 * # No outbound call — everything happens from the inbound payload.
 *
 * # Business filters (applied only AFTER authenticity is established)
 *
 *  1. `transaction_type` must be `'PAYIN'`. Payout webhooks are
 *     ignored here — they will be handled by a dedicated controller
 *     when payout support lands.
 * Replies 200 OK to keep MyCoolPay from retrying — but logs a
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
        private ProviderApplicationRepository $applications,
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

        // The `application` field identifies WHICH Project's MyCoolPay
        // application this callback claims to be for — each Project has
        // its own key pair (ADR-0051), so the private key to verify
        // against is resolved from it, never a single platform-wide
        // config value. This also makes the old "application must match
        // our one configured key" business filter obsolete: a forged
        // `application` can no longer pass, because it would need that
        // application's own private key too, which is exactly what the
        // signature check below proves.
        $publicKey = $body['application'] ?? null;
        if (! is_string($publicKey) || $publicKey === '') {
            $this->logger->error('my-coolpay.webhook.missing_application');

            return false;
        }

        $application = $this->applications->findByProviderAndPublicKey('my-coolpay', $publicKey);
        if ($application === null) {
            // No fallback to a shared/default key — an application we
            // don't recognise has no basis to be trusted.
            $this->logger->error('my-coolpay.webhook.unknown_application', [
                'application_prefix' => substr($publicKey, 0, 8).'...',
            ]);

            return false;
        }

        if (! $this->authenticator->hasValidSignature($body, $application->privateKey)) {
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

        $transactionRef = self::stringField($body, 'transaction_ref');
        $appTransactionRef = self::stringField($body, 'app_transaction_ref');
        $rawStatus = self::stringField($body, 'transaction_status');
        // `transaction_reason` is never a provider explanation — it's
        // MyCoolPay echoing back the `reason` string WE sent at
        // initiation, verbatim, on every status (confirmed live: a real
        // FAILED transaction still carried our own "Test paiement…" text
        // there). `transaction_message` is MyCoolPay's own human-readable
        // account of what happened ("Votre transaction n'a pas pu être
        // traitée…") — the same field `MyCoolPayAdapter::toPaymentReceipt()`
        // already uses correctly; this brought the webhook path in line
        // with it.
        $reasonRaw = $body['transaction_message'] ?? null;
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
     * @param array<string, mixed> $body
     */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
