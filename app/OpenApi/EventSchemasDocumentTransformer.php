<?php

declare(strict_types=1);

namespace App\OpenApi;

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

    private function buildWebhookEventSchema(): Schema
    {
        $id = new OpenApiTypes\StringType;
        $id->setDescription('Unique event id (UUIDv7). Use this to deduplicate on the receiver side across retries.');
        $id->example('01HMZ9XK3P-9XK3P-9XK3P');

        $type = new OpenApiTypes\StringType;
        $type->setDescription('One of the values from the WebhookEventType enum. Decide whether to consume based on this.');
        $type->example('payment.status_changed');

        $paymentId = new OpenApiTypes\StringType;
        $paymentId->example('01HMZ9XK3P-PAYMENT-001');
        $status = new OpenApiTypes\StringType;
        $status->enum(['pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled']);
        $status->example('succeeded');
        $amount = new OpenApiTypes\IntegerType;
        $amount->example(50000);
        $currency = new OpenApiTypes\StringType;
        $currency->example('XAF');
        $errorMessage = new OpenApiTypes\StringType;
        $errorMessage->example(null);

        $paymentDataExample = new OpenApiTypes\ObjectType;
        $paymentDataExample->addProperty('payment_id', $paymentId);
        $paymentDataExample->addProperty('status', $status);
        $paymentDataExample->addProperty('amount', $amount);
        $paymentDataExample->addProperty('currency', $currency);
        $paymentDataExample->addProperty('error_message', $errorMessage);
        $paymentDataExample->setDescription('`data` payload when `type === "payment.status_changed"`. Schema depends on `type` — see WebhookEventType for the full list.');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('id', $id);
        $schema->addProperty('type', $type);
        $schema->addProperty('data', $paymentDataExample);
        $schema->setRequired(['id', 'type', 'data']);
        // Schema wrapper's `description` is dropped at serialization
        // (see `Schema::toArray()` — only `title` survives). The
        // description has to live on the inner Type.
        $schema->description = 'Envelope POSTed to every endpoint registered via `POST /v1/webhook-endpoints`. Body is `{id, type, data}`. Headers `X-Signature`, `X-Timestamp`, `X-Event-Id`, `X-Event-Type` are always present (HMAC-SHA256 over `timestamp + "." + raw_body`). The `_headers` block in the example below is documentation only — the actual headers are on the HTTP request, not in the body.';
        $schema->example([
            'id' => '01HMZ9XK3P-9XK3P-9XK3P',
            'type' => 'payment.status_changed',
            'data' => [
                'payment_id' => '01HMZ9XK3P-PAYMENT-001',
                'status' => 'succeeded',
                'amount' => 50000,
                'currency' => 'XAF',
                'error_message' => null,
            ],
            '_headers' => [
                'X-Signature' => '5e7c2d...c0a1 (HMAC-SHA256 of "1763875200.<raw_body>" with the endpoint secret)',
                'X-Timestamp' => '1763875200',
                'X-Event-Id' => '01HMZ9XK3P-9XK3P-9XK3P',
                'X-Event-Type' => 'payment.status_changed',
            ],
        ]);

        $built = Schema::fromType($schema);
        /** @var Schema $built */

        return $built;
    }

    private function buildWebhookEventTypeSchema(): Schema
    {
        $enum = new OpenApiTypes\StringType;
        $enum->enum([
            'payment.status_changed',
            'notification.status_changed',
            'notification.failed',
            'wallet.credited',
            'wallet.debited',
        ]);
        $enum->description = 'Closed set of `type` values the Core emits via the outbox. `*` (subscriber-only, never produced) is allowed as a wildcard in `POST /v1/webhook-endpoints`. Adding a new event type is a Core release, not a config change.';

        $built = Schema::fromType($enum);
        /** @var Schema $built */

        return $built;
    }

    private function buildPaymentCallbackEventSchema(): Schema
    {
        $event = new OpenApiTypes\StringType;
        $event->setDescription('Always `payment.status_changed` on this plane.');
        $event->example('payment.status_changed');

        $paymentId = new OpenApiTypes\StringType;
        $paymentId->example('01HMZ9XK3P-PAYMENT-001');

        $status = new OpenApiTypes\StringType;
        $status->enum([
            'pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled',
        ]);
        $status->setDescription('Canonical payment status. Mirrors the value returned by `GET /v1/payments/{payment_id}`.');

        $amount = new OpenApiTypes\IntegerType;
        $amount->setDescription('Minor units.');
        $amount->example(50000);

        $currency = new OpenApiTypes\StringType;
        $currency->setDescription('ISO 4217.');
        $currency->example('XAF');

        $errorMessage = new OpenApiTypes\StringType;
        $errorMessage->setDescription('null when the payment succeeded.');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('event', $event);
        $schema->addProperty('payment_id', $paymentId);
        $schema->addProperty('status', $status);
        $schema->addProperty('amount', $amount);
        $schema->addProperty('currency', $currency);
        $schema->addProperty('error_message', $errorMessage);
        $schema->setRequired(['event', 'payment_id', 'status', 'amount', 'currency', 'error_message']);
        $schema->description = 'Body POSTed to the `callback_url` of a payment when it reaches a terminal state. Signed with HMAC-SHA256 (same algorithm as the webhook plane, but the secret is per-payment — returned once in the initiation response). Headers: `X-Signature`, `X-Timestamp`.';
        $schema->example([
            'event' => 'payment.status_changed',
            'payment_id' => '01HMZ9XK3P-PAYMENT-001',
            'status' => 'succeeded',
            'amount' => 50000,
            'currency' => 'XAF',
            'error_message' => null,
        ]);

        $built = Schema::fromType($schema);
        /** @var Schema $built */

        return $built;
    }
}
