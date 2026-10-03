<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Payment\Contracts\Events\PaymentStatusNotification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0061 — closes audit 2026-10-03 §4.2: the payload the Core actually
 * emits for `payment.status_changed` IS the documented one. G9 (oasdiff)
 * only diffs the document against itself; this pins the document to the
 * code that produces the payload.
 */
final class PaymentEventContractTest extends TestCase
{
    #[Test]
    public function the_emitted_payload_matches_the_published_schema_exactly(): void
    {
        /** @var array{components: array{schemas: array<string, array{properties: array{data?: array{properties?: array<string, mixed>}}}>}} $document */
        $document = json_decode((string) file_get_contents(__DIR__.'/../../openapi/v1.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach (['WebhookEvent', 'PaymentCallbackEvent'] as $schemaName) {
            $data = $document['components']['schemas'][$schemaName]['properties']['data'] ?? [];
            $documented = array_keys($data['properties'] ?? []);
            sort($documented);

            $emitted = array_keys((new PaymentStatusNotification('p', 'r', 'succeeded', true, 1, 'XAF', null, '2026-10-03T10:15:00Z'))->toPayload());
            sort($emitted);

            self::assertSame($emitted, $documented, "{$schemaName}.data drifted from PaymentStatusNotification::toPayload()");

            $fields = PaymentStatusNotification::FIELDS;
            sort($fields);
            self::assertSame($fields, $emitted);
        }
    }

    #[Test]
    public function internal_provider_details_are_never_published(): void
    {
        $payload = (new PaymentStatusNotification('p', 'r', 'failed', true, 1, 'XAF', 'Insufficient balance', '2026-10-03T10:15:00Z'))->toPayload();

        self::assertArrayNotHasKey('provider_code', $payload);
        self::assertArrayNotHasKey('transaction_ref', $payload);
    }
}
