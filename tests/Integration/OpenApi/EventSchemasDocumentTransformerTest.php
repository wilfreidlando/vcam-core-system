<?php

declare(strict_types=1);

use App\OpenApi\EventSchemasDocumentTransformer;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use PHPUnit\Framework\Assert;

/**
 * Locks the B5/B6 fix from the doc-completeness commit: the OpenAPI
 * document must publish three reusable schemas that an integrator
 * needs to consume the Core's outbound events but cannot discover
 * from any inbound operation alone — `WebhookEvent`,
 * `WebhookEventType`, `PaymentCallbackEvent`. Without them, the
 * doc accurately describes every endpoint the Core accepts but
 * tells the caller nothing about what the Core emits.
 */
function buildOpenApi(): OpenApi
{
    $openApi = new OpenApi('test');
    $config = app(GeneratorConfig::class);
    $context = new OpenApiContext($openApi, $config);

    $transformer = new EventSchemasDocumentTransformer;
    $transformer->handle($openApi, $context);

    return $openApi;
}

it('publishes WebhookEvent schema with the documented envelope shape', function (): void {
    $openApi = buildOpenApi();
    $schema = $openApi->components->schemas['WebhookEvent'] ?? null;
    Assert::assertInstanceOf(Schema::class, $schema);
    /** @var Schema $schema */
    $innerType = $schema->type;
    Assert::assertInstanceOf(ObjectType::class, $innerType);
    /** @var ObjectType $innerType */

    // Description is set on the inner Type (not the Schema wrapper — the
    // wrapper's `description` is dropped at serialization per
    // Schema::toArray()).
    Assert::assertStringContainsString('Envelope POSTed', $innerType->description);

    $props = $innerType->properties;
    Assert::assertArrayHasKey('id', $props);
    Assert::assertArrayHasKey('type', $props);
    Assert::assertArrayHasKey('data', $props);

    /** @var Type $idType */ $idType = $props['id'];
    Assert::assertSame('string', $idType->type);
    /** @var Type $typeType */ $typeType = $props['type'];
    Assert::assertSame('string', $typeType->type);
    /** @var Type $dataType */ $dataType = $props['data'];
    Assert::assertSame('object', $dataType->type);

    Assert::assertContains('id', $innerType->required);
    Assert::assertContains('type', $innerType->required);
    Assert::assertContains('data', $innerType->required);

    // Examples are surfaced under OpenAPI 3.0's plural `examples` key
    // in the final JSON, but at the Type level they're stored on the
    // deprecated `example` (singular) field. The Type::toArray()
    // method prepends `$this->example` to `$this->examples` for the
    // JSON output. So we check the singular property here.
    $example = $innerType->example;
    Assert::assertIsArray($example);
    Assert::assertArrayHasKey('id', $example);
    Assert::assertArrayHasKey('type', $example);
    Assert::assertArrayHasKey('data', $example);
    Assert::assertSame('payment.status_changed', $example['type']);
});

it('publishes WebhookEventType schema with the closed enum of emitted types', function (): void {
    $openApi = buildOpenApi();
    $schema = $openApi->components->schemas['WebhookEventType'] ?? null;
    Assert::assertInstanceOf(Schema::class, $schema);
    /** @var Schema $schema */
    Assert::assertSame([
        'payment.status_changed',
        'notification.status_changed',
        'notification.failed',
        'wallet.credited',
        'wallet.debited',
    ], $schema->type->enum);
});

it('publishes PaymentCallbackEvent schema with the documented callback body shape', function (): void {
    $openApi = buildOpenApi();
    $schema = $openApi->components->schemas['PaymentCallbackEvent'] ?? null;
    Assert::assertInstanceOf(Schema::class, $schema);
    /** @var Schema $schema */
    $innerType = $schema->type;
    Assert::assertInstanceOf(ObjectType::class, $innerType);

    $props = $innerType->properties;
    Assert::assertArrayHasKey('event', $props);
    Assert::assertArrayHasKey('payment_id', $props);
    Assert::assertArrayHasKey('status', $props);
    Assert::assertArrayHasKey('amount', $props);
    Assert::assertArrayHasKey('currency', $props);
    Assert::assertArrayHasKey('error_message', $props);

    // `status` must be the closed set of canonical payment statuses
    /** @var Type $statusType */
    $statusType = $props['status'];
    Assert::assertSame([
        'pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled',
    ], $statusType->enum);

    Assert::assertSame([
        'event', 'payment_id', 'status', 'amount', 'currency', 'error_message',
    ], $innerType->required);

    $example = $innerType->example;
    Assert::assertIsArray($example);
    Assert::assertSame('payment.status_changed', $example['event']);
});
