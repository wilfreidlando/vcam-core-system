<?php

declare(strict_types=1);

namespace App\OpenApi;

use Core\Webhook\Contracts\WebhookEventTypes;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;

/**
 * Publishes the schemas a third-party integrator MUST know to consume
 * the Core's outbound events. Three schemas:
 *
 * - `WebhookEvent` — the envelope POSTed to every endpoint registered
 *   via `POST /v1/webhook-endpoints`. Body is `{id, type, data}` plus
 *   headers `X-Signature` / `X-Timestamp` / `X-Event-Id` /
 *   `X-Event-Type` (HMAC-SHA256 over `timestamp + "." + body`).
 *
 * - `WebhookEventType` — the closed set of `type` values the Core emits.
 *   `*` is a wildcard a Project can subscribe to via
 *   `POST /v1/webhook-endpoints` but is never produced by the Core.
 *
 * - `PaymentCallbackEvent` — the body POSTed to a per-payment
 *   `callback_url` (the server-to-server notification target on
 *   `POST /v1/payments`). Distinct from the webhook plane above
 *   because (a) the signing algorithm is identical but the secret is
 *   per-payment, returned once at initiation, never again, and (b) the
 *   schema is narrower — payment lifecycle only.
 *
 * None of these schemas is attached to an inbound operation (the
 * Core doesn't accept these payloads, it produces them). They live in
 * `components.schemas` and are referenced from the descriptions of
 * the outbound-facing operations (webhook endpoint registration,
 * payment initiation).
 */
final class EventSchemasDocumentTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->addSchema('WebhookEvent', $this->buildWebhookEventSchema());
        $document->components->addSchema('WebhookEventType', $this->buildWebhookEventTypeSchema());
        $document->components->addSchema('PaymentCallbackEvent', $this->buildPaymentCallbackEventSchema());
    }

    /**
     * ADR-0061 — `data` of `payment.status_changed`, identical on the webhook
     * plane and on a payment's `callback_url`. Its keys are pinned to
     * `PaymentStatusNotification::FIELDS` by `PaymentEventContractTest`.
     */
    private function paymentStatusChangedData(): OpenApiTypes\ObjectType
    {
        $paymentId = new OpenApiTypes\StringType;
        $paymentId->setDescription('The payment id returned when you created it.');
        $paymentId->example('01a1c3b2-6f1e-7a2b-9c4d-5e6f7a8b9c0d');

        $reference = new OpenApiTypes\StringType;
        $reference->setDescription('Your own reference: the `Idempotency-Key` you sent when creating the payment.');
        $reference->example('order-2026-0042');

        $status = new OpenApiTypes\StringType;
        $status->enum(['pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled']);
        $status->setDescription('Canonical payment status. Intermediate: `pending`, `processing`. Final: `succeeded`, `failed`, `expired`, `canceled`, `refunded`.');
        $status->example('succeeded');

        $isFinal = new OpenApiTypes\BooleanType;
        $isFinal->setDescription('`true` once the outcome is settled: deliver the order on `succeeded`, offer a new attempt otherwise. `false` = keep waiting.');
        $isFinal->example(true);

        $amount = new OpenApiTypes\IntegerType;
        $amount->setDescription('Minor units (XAF has none: 5000 = 5 000 XAF).');
        $amount->example(5000);

        $currency = new OpenApiTypes\StringType;
        $currency->setDescription('ISO 4217.');
        $currency->example('XAF');

        $failureReason = new OpenApiTypes\StringType;
        $failureReason->setDescription('Why the payment did not succeed; `null` on success and on intermediate statuses.');
        $failureReason->nullable(true);
        $failureReason->example(null);

        $occurredAt = new OpenApiTypes\StringType;
        $occurredAt->format('date-time');
        $occurredAt->setDescription('When the Core recorded this status (UTC). Use it to ignore an older notification arriving after a newer one.');
        $occurredAt->example('2026-10-03T10:15:00Z');

        $data = new OpenApiTypes\ObjectType;
        $data->addProperty('payment_id', $paymentId);
        $data->addProperty('reference', $reference);
        $data->addProperty('status', $status);
        $data->addProperty('is_final', $isFinal);
        $data->addProperty('amount', $amount);
        $data->addProperty('currency', $currency);
        $data->addProperty('failure_reason', $failureReason);
        $data->addProperty('occurred_at', $occurredAt);
        $data->setRequired(['payment_id', 'reference', 'status', 'is_final', 'amount', 'currency', 'failure_reason', 'occurred_at']);
        $data->setDescription('`data` of a `payment.status_changed` event.');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function examplePaymentEvent(): array
    {
        return [
            'id' => '01a1c3b2-7d2e-7f10-8a9b-0c1d2e3f4a5b',
            'type' => 'payment.status_changed',
            'data' => [
                'payment_id' => '01a1c3b2-6f1e-7a2b-9c4d-5e6f7a8b9c0d',
                'reference' => 'order-2026-0042',
                'status' => 'succeeded',
                'is_final' => true,
                'amount' => 5000,
                'currency' => 'XAF',
                'failure_reason' => null,
                'occurred_at' => '2026-10-03T10:15:00Z',
            ],
        ];
    }

    private function buildWebhookEventSchema(): Schema
    {
        $id = new OpenApiTypes\StringType;
        $id->setDescription('Unique event id. The same event is delivered again on retry or replay with the same id: deduplicate on it.');
        $id->example('01a1c3b2-7d2e-7f10-8a9b-0c1d2e3f4a5b');

        $type = new OpenApiTypes\StringType;
        $type->setDescription('One of the values from the WebhookEventType enum. Decide whether to consume based on this.');
        $type->example('payment.status_changed');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('id', $id);
        $schema->addProperty('type', $type);
        $schema->addProperty('data', $this->paymentStatusChangedData());
        $schema->setRequired(['id', 'type', 'data']);
        // Schema wrapper's `description` is dropped at serialization — it
        // has to live on the inner Type.
        $schema->description = 'Body POSTed to every endpoint registered via `POST /v1/webhook-endpoints` — and, identically, to a payment\'s `callback_url`. Headers: `X-Signature` (hex HMAC-SHA256 of `X-Timestamp + "." + raw body`, with your endpoint secret — or the payment\'s `callback_secret`), `X-Timestamp` (Unix seconds), `X-Event-Id`, `X-Event-Type`, `X-Delivery-Id`. Answer `2xx` within 10 s; deduplicate on `id`. The example shows `payment.status_changed`.';
        $schema->example(self::examplePaymentEvent());

        $built = Schema::fromType($schema);
        /** @var Schema $built */

        return $built;
    }

    private function buildWebhookEventTypeSchema(): Schema
    {
        $enum = new OpenApiTypes\StringType;
        $enum->enum(WebhookEventTypes::ALL);
        $enum->description = 'Closed set of `type` values the Core emits via the outbox. `*` (subscriber-only, never produced) is allowed as a wildcard in `POST /v1/webhook-endpoints`. Adding a new event type is a Core release, not a config change.';

        $built = Schema::fromType($enum);
        /** @var Schema $built */

        return $built;
    }

    private function buildPaymentCallbackEventSchema(): Schema
    {
        $id = new OpenApiTypes\StringType;
        $id->setDescription('Unique event id — the same as the webhook plane would use. Deduplicate on it.');
        $id->example('01a1c3b2-7d2e-7f10-8a9b-0c1d2e3f4a5b');

        $type = new OpenApiTypes\StringType;
        $type->setDescription('Always `payment.status_changed` on this plane.');
        $type->example('payment.status_changed');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('id', $id);
        $schema->addProperty('type', $type);
        $schema->addProperty('data', $this->paymentStatusChangedData());
        $schema->setRequired(['id', 'type', 'data']);
        $schema->description = 'Body POSTed to a payment\'s `callback_url` at every status change — byte-for-byte the webhook envelope (ADR-0061). Signed with the per-payment `callback_secret` returned once at creation: `X-Signature` = hex HMAC-SHA256 of `X-Timestamp + "." + raw body`. Headers `X-Event-Id`, `X-Event-Type` too.';
        $schema->example(self::examplePaymentEvent());

        $built = Schema::fromType($schema);
        /** @var Schema $built */

        return $built;
    }
}
