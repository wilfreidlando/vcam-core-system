<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\TrustProxiesFromSidecar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0064 — another container on the shared `observability` network must
 * not be able to spoof a client IP by calling the app directly.
 */
final class TrustProxiesFromSidecarTest extends TestCase
{
    #[Test]
    public function without_a_configured_token_private_proxies_are_trusted_as_before(): void
    {
        config(['trustedproxy.sidecar_token' => '']);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/_test/ip', ['X-Forwarded-For' => '41.202.219.70'])
            ->assertJsonPath('ip', '41.202.219.70');
    }

    #[Test]
    public function the_sidecar_token_makes_forwarded_headers_trusted_and_is_stripped(): void
    {
        config(['trustedproxy.sidecar_token' => 's3cret-token']);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/_test/ip', ['X-Forwarded-For' => '41.202.219.70', TrustProxiesFromSidecar::HEADER => 's3cret-token'])
            ->assertJsonPath('ip', '41.202.219.70')
            ->assertJsonPath('token_seen', false);
    }

    #[Test]
    public function a_direct_caller_without_the_token_cannot_spoof_its_ip(): void
    {
        config(['trustedproxy.sidecar_token' => 's3cret-token']);

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.9'])
            ->getJson('/_test/ip', ['X-Forwarded-For' => '41.202.219.70', TrustProxiesFromSidecar::HEADER => 'wrong'])
            ->assertJsonPath('ip', '172.20.0.9');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'token_seen' => $request->headers->has(TrustProxiesFromSidecar::HEADER),
        ]);
    }
}
