<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit 2026-10-03 §2.2 — behind two Docker-internal proxy hops, the client
 * IP must be the real caller, not the first proxy.
 */
final class TrustedProxiesTest extends TestCase
{
    #[Test]
    public function the_real_client_ip_is_resolved_through_two_proxy_hops(): void
    {
        $response = $this->call('GET', '/_test/client-ip', server: [
            'REMOTE_ADDR' => '172.18.0.5',                                   // nginx sidecar
            'HTTP_X_FORWARDED_FOR' => '41.202.219.70, 172.18.0.3',           // client, nginx-proxy
        ]);

        $response->assertOk();
        self::assertSame('41.202.219.70', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function a_spoofed_left_most_forwarded_for_entry_is_ignored(): void
    {
        $response = $this->call('GET', '/_test/client-ip', server: [
            'REMOTE_ADDR' => '172.18.0.5',
            // The client claims to be MyCoolPay; nginx-proxy appends its real address.
            'HTTP_X_FORWARDED_FOR' => '15.236.140.89, 41.202.219.70, 172.18.0.3',
        ]);

        self::assertSame('41.202.219.70', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function a_direct_public_caller_cannot_inject_a_forwarded_for(): void
    {
        $response = $this->call('GET', '/_test/client-ip', server: [
            'REMOTE_ADDR' => '41.202.219.70',
            'HTTP_X_FORWARDED_FOR' => '15.236.140.89',
        ]);

        self::assertSame('41.202.219.70', (string) $response->baseResponse->getContent());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/client-ip', fn (Request $request): string => (string) $request->ip());
    }
}
