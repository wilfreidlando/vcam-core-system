<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Support\Http\Middleware\LimitRequestBody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29, écart #13 (ADR-0081 S-14) : aucune borne de taille de corps
 * applicative — la seule limite était le `client_max_body_size 20m` de nginx — et
 * `LogHttpExchange` décodait TOUT le corps de chaque requête.
 *
 * `LimitRequestBody` est posé en tête du groupe `api` : 413 au-delà de 1 Mio, 400 au-delà de
 * 20 niveaux de JSON, tous deux en `application/problem+json`, avant l'authentification.
 */
final class RequestBodyLimitTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/v1/webhook-endpoints';

    #[Test]
    public function the_limiter_heads_the_api_group_and_has_an_alias(): void
    {
        self::assertSame(LimitRequestBody::class, Route::getMiddlewareGroups()['api'][0] ?? null);
        self::assertSame(LimitRequestBody::class, Route::getMiddleware()['body.limit'] ?? null);
    }

    #[Test]
    public function a_body_over_one_mebibyte_is_a_413_problem_before_anything_reads_it(): void
    {
        $response = $this->postJson(self::ENDPOINT, ['reason' => str_repeat('a', LimitRequestBody::MAX_BYTES + 1)]);

        $response->assertStatus(413)->assertHeader('Content-Type', 'application/problem+json');
        self::assertStringEndsWith('/payload-too-large', (string) $response->json('type'));
    }

    #[Test]
    public function a_body_just_under_the_ceiling_goes_on_to_authentication(): void
    {
        // 401, not 413: the body is accepted, the missing key is what stops the call.
        $this->postJson(self::ENDPOINT, ['reason' => str_repeat('a', 900_000)])->assertStatus(401);
    }

    #[Test]
    public function a_json_nested_over_twenty_levels_is_a_400_problem(): void
    {
        $response = $this->rawJson(str_repeat('[', 21).str_repeat(']', 21));

        $response->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json');
        self::assertStringEndsWith('/json-too-deep', (string) $response->json('type'));
    }

    #[Test]
    public function a_json_nested_on_exactly_twenty_levels_is_accepted(): void
    {
        $this->rawJson(str_repeat('[', 20).str_repeat(']', 20))->assertStatus(401);
    }

    #[Test]
    public function an_oversized_body_is_neither_decoded_nor_logged_not_even_its_first_bytes(): void
    {
        $logger = $this->recordingLogger();
        $this->app->instance(LoggerInterface::class, $logger);

        // 20 KB: under the 1 MiB ceiling (so it reaches the logger), over the 16 KB the log keeps.
        $this->postJson('/v1/webhook-endpoints', ['password' => 'hunter2-do-not-log', 'padding' => str_repeat('x', 20_000)]);

        $received = array_values(array_filter($logger->records, static fn (array $r): bool => $r['message'] === 'http.request.received'));
        self::assertCount(1, $received);

        $encoded = (string) json_encode($received[0]['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('non journalisé', $encoded);
        self::assertStringNotContainsString('hunter2-do-not-log', $encoded);
        self::assertStringNotContainsString('xxxxxxxxxx', $encoded);
    }

    #[Test]
    public function a_json_body_that_cannot_be_decoded_is_logged_by_its_size_only(): void
    {
        $logger = $this->recordingLogger();
        $this->app->instance(LoggerInterface::class, $logger);

        // Broken JSON used to be logged as raw text, so unredacted: a password in it went to Loki.
        $this->call('POST', '/v1/webhook-endpoints', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"password":"hunter2-do-not-log",');

        $received = array_values(array_filter($logger->records, static fn (array $r): bool => $r['message'] === 'http.request.received'));
        self::assertCount(1, $received);

        $encoded = (string) json_encode($received[0]['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('hunter2-do-not-log', $encoded);
        self::assertStringContainsString('non journalisé', $encoded);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function rawJson(string $body)
    {
        return $this->call('POST', self::ENDPOINT, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
