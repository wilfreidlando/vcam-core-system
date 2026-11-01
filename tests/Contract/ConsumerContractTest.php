<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Webhook\Contracts\WebhookEventTypes;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G10 — « No consumer contract regression ».
 *
 * The API document is guarded against **itself** (G9: oasdiff compares two
 * versions of the document) and the emitted payloads are pinned to it
 * (`PaymentEventContractTest`). What neither can catch is a change that
 * breaks a **consumer**: an endpoint that disappears or changes shape
 * between two releases, and the consumer only finds out when it calls.
 *
 * These tests confront the pinned consumer contract with the two truths
 * of the Core — the published document and the live routing table — and
 * with the events the Core can still emit.
 */
final class ConsumerContractTest extends TestCase
{
    #[Test]
    public function every_endpoint_the_consumer_calls_is_still_published(): void
    {
        $document = $this->document();

        foreach (ConsumerContractFixture::ENDPOINTS as $endpoint) {
            $path = $endpoint['path'];

            self::assertArrayHasKey(
                $path,
                $document['paths'],
                sprintf('The consumer calls %s %s — it is no longer in the API document.', $endpoint['method'], $path),
            );

            self::assertArrayHasKey(
                $endpoint['method'],
                $document['paths'][$path],
                sprintf('%s %s changed method: the consumer calls %s.', $path, $endpoint['method'], $endpoint['method']),
            );
        }
    }

    #[Test]
    public function every_endpoint_the_consumer_calls_is_still_routed(): void
    {
        $routed = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (! is_string($method)) {
                    continue;
                }

                $routed[strtolower($method).' '.$route->uri()] = true;
            }
        }

        foreach (ConsumerContractFixture::ENDPOINTS as $endpoint) {
            $uri = 'v1/'.ltrim($endpoint['path'], '/');

            self::assertArrayHasKey(
                strtolower($endpoint['method']).' '.$uri,
                $routed,
                sprintf(
                    'The consumer calls %s %s — the route no longer exists. A document that still publishes it is a lie.',
                    $endpoint['method'],
                    $endpoint['path'],
                ),
            );
        }
    }

    #[Test]
    public function every_event_the_consumer_subscribes_to_can_still_be_emitted(): void
    {
        $document = $this->document();
        $published = $document['components']['schemas']['WebhookEventType']['enum'] ?? [];

        self::assertIsArray($published);

        foreach (ConsumerContractFixture::EVENTS as $event) {
            self::assertContains(
                $event,
                WebhookEventTypes::ALL,
                sprintf('The Core no longer emits `%s`, which the consumer subscribes to.', $event),
            );

            self::assertContains(
                $event,
                $published,
                sprintf('`%s` is still emitted but no longer published: a consumer cannot subscribe to it.', $event),
            );
        }
    }

    /**
     * The published API document.
     *
     * @return array{
     *     paths: array<string, array<string, mixed>>,
     *     components: array{schemas: array<string, array<string, mixed>>},
     * }
     */
    private function document(): array
    {
        /** @var array{paths: array<string, array<string, mixed>>, components: array{schemas: array<string, array<string, mixed>>}} $document */
        $document = json_decode(
            (string) file_get_contents(__DIR__.'/../../openapi/v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $document;
    }
}
