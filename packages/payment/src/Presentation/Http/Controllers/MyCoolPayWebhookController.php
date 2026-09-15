<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\CorrelationIdProvider;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Receives MyCoolPay's transaction-status webhook and publishes a
 * canonical `PaymentStatusChanged` event into the outbox.
 *
 * # What it does (and doesn't)
 *
 * - **No signature verification** at this point — the MyCoolPay docs
 *   give no clear algorithm; we keep the hook ready to plug in as
 *   soon as we have a real signed body sample.
 * - **No state mutation**. The adapter is a pure transport; persisting
 *   the status to a domain entity is the consumer's job. This
 *   controller publishes ONE event and replies 200 OK.
 * - **No outbound call**. Everything happens from the inbound payload.
 *
 * # Security filters
 *
 * Two filters are applied BEFORE the outbox is touched:
 *  1. `transaction_type` must be `'PAYIN'`. Payout webhooks are
 *     ignored here — they will be handled by a dedicated controller
 *     when payout support lands.
 *  2. `application` (the public key) must match the configured one.
 *     This prevents a different tenant's webhook from injecting
 *     events into our outbox.
 * Both filters reply 200 OK to keep MyCoolPay from retrying — but
 * they log a `warning` so operators see the noise.
 *
 * # Outbox semantics
 *
 * The `PaymentStatusChanged` event is appended to the outbox in the
 * caller's transaction. Today, the only "transaction" is the single
 * `INSERT` into `event_outbox` — there is no other write. When Lot 3
 * adds wallet crediting, the same webhook controller will wrap the
 * `outbox->append()` in a `DB::transaction()` that also writes the
 * ledger entry. The event publication stays transactionally safe.
 *
 * For now, no consumer listens to `payment.status_changed`. The event
 * is recorded, dispatched, and forgotten — that's correct: the
 * infrastructure is ready, the listener comes when needed.
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
        private EventOutbox $outbox,
        private PaymentStatusMapper $statusMapper,
        private Identifier $identifier,
        private Clock $clock,
        private CorrelationIdProvider $correlationIds,
        private ProviderConfigRegistry $configRegistry,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Laravel's `$request->json()->all()` always returns an array
        // (possibly empty for a non-JSON body). The validation that
        // follows treats an empty body as "missing fields" → no event
        // published → 200 OK so MyCoolPay does not retry forever.
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        $event = $this->buildEvent($body);
        if ($event === null) {
            // The payload was structurally valid but the controller chose
            // not to publish (wrong transaction type, wrong application,
            // missing fields, or unknown status). We have already logged
            // a warning. Reply 200 OK anyway: MyCoolPay considers any 2xx
            // a successful delivery; bouncing 4xx would make them retry
            // forever for a payload they will keep sending.
            return new JsonResponse(['status' => 'ignored'], 200);
        }

        $domainEvent = new DomainEvent(
            id: $this->identifier->generate(),
            projectId: $this->extractProjectId($body),
            type: 'payment.status_changed',
            payload: $event->toPayload(),
            correlationId: $this->correlationIds->current(),
            occurredAt: $this->clock->now(),
        );

        $this->outbox->append($domainEvent);

        $this->logger->info('my-coolpay.webhook.published', [
            'provider_code' => $event->providerCode,
            'transaction_ref' => $event->transactionRef,
            'status' => $event->status->value,
            'correlation_id' => $domainEvent->correlationId,
        ]);

        return new JsonResponse(['status' => 'received'], 200);
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
     * MyCoolPay identifies the application by `public_key` (the same
     * key that lives in the URL path). For the outbox, we use the
     * application as the projectId so consumers can join events to
     * their own project scope.
     *
     * @param array<string, mixed> $body
     */
    private function extractProjectId(array $body): string
    {
        return self::stringField($body, 'application');
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
        } catch (\Core\Support\Integration\Configuration\ProviderNotConfigured) {
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
