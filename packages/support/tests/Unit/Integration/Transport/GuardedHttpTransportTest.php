<?php

declare(strict_types=1);

use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Security\OutboundUrlGuard;
use Core\Support\Integration\Transport\GuardedHttpTransport;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Integration\Transport\PinnableHttpTransport;

/**
 * Audit 2026-10-03 §2.1 — a Project-chosen URL is re-checked at connect
 * time and the connection pinned to the checked address.
 */
final class PinRecordingTransport implements PinnableHttpTransport
{
    /** @var list<string> */
    public array $pins = [];

    public int $sends = 0;

    public function pinnedTo(string $host, int $port, string $ip): HttpTransport
    {
        $this->pins[] = sprintf('%s:%d:%s', $host, $port, $ip);

        return $this;
    }

    public function send(HttpMethod $method, string $url, array $headers = [], string|array|null $body = null, ?float $timeoutSeconds = null): ExternalResponse
    {
        $this->sends++;

        return new ExternalResponse(status: 200, headers: [], body: null, rawBody: '', latencyMs: 1);
    }
}

it('pins the connection to the address it checked', function (): void {
    $inner = new PinRecordingTransport;
    $transport = new GuardedHttpTransport($inner, new OutboundUrlGuard(fn (string $h): array => ['93.184.216.34']));

    $transport->send(HttpMethod::POST, 'https://hooks.example.com/core', [], '{}');

    expect($inner->pins)->toBe(['hooks.example.com:443:93.184.216.34'])
        ->and($inner->sends)->toBe(1);
});

it('uses the explicit port when the URL carries one', function (): void {
    $inner = new PinRecordingTransport;
    $transport = new GuardedHttpTransport($inner, new OutboundUrlGuard(fn (string $h): array => ['93.184.216.34']));

    $transport->send(HttpMethod::POST, 'https://hooks.example.com:8443/core');

    expect($inner->pins)->toBe(['hooks.example.com:8443:93.184.216.34']);
});

it('refuses to send when the host now resolves to an internal address (DNS rebinding)', function (): void {
    $inner = new PinRecordingTransport;
    $transport = new GuardedHttpTransport($inner, new OutboundUrlGuard(fn (string $h): array => ['169.254.169.254']));

    expect(fn () => $transport->send(HttpMethod::POST, 'https://rebind.example/core'))
        ->toThrow(ProviderPermanentError::class);

    expect($inner->sends)->toBe(0);
});

it('passes straight through when enforcement is disabled', function (): void {
    $inner = new PinRecordingTransport;
    $transport = new GuardedHttpTransport($inner, new OutboundUrlGuard(fn (string $h): array => []), enforce: false);

    $transport->send(HttpMethod::POST, 'https://whatever.example/core');

    expect($inner->sends)->toBe(1)->and($inner->pins)->toBe([]);
});
