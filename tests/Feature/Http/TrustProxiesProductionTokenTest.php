<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\MissingTrustedProxyToken;
use App\Http\Middleware\TrustProxiesFromSidecar;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #8 — an empty `TRUSTED_PROXY_TOKEN` made the app trust
 * every private address, letting a neighbouring container forge the client
 * IP. In production the token is now mandatory; other environments keep
 * working without it.
 */
final class TrustProxiesProductionTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'too short' => ['CHANGE_ME_openssl_rand_hex_32'];
    }

    #[Test]
    #[DataProvider('unusableTokens')]
    public function production_refuses_an_unusable_token(mixed $token): void
    {
        $this->expectException(MissingTrustedProxyToken::class);

        TrustProxiesFromSidecar::assertConfigured('production', $token);
    }

    #[Test]
    #[DataProvider('unusableTokens')]
    public function production_refuses_to_serve_a_request_without_a_usable_token(mixed $token): void
    {
        $this->app['env'] = 'production';
        config(['trustedproxy.sidecar_token' => $token]);

        $this->withoutExceptionHandling();
        $this->expectException(MissingTrustedProxyToken::class);

        $this->get('/_test/ip');
    }

    #[Test]
    public function production_accepts_a_generated_token(): void
    {
        TrustProxiesFromSidecar::assertConfigured('production', bin2hex(random_bytes(32)));

        $this->addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('nonProductionEnvironments')]
    public function other_environments_start_without_a_token(string $environment): void
    {
        TrustProxiesFromSidecar::assertConfigured($environment, '');

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'testing' => ['testing'];
        yield 'staging' => ['staging'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', static fn () => response()->json(['ip' => request()->ip()]));
    }
}
