<?php

declare(strict_types=1);

use Core\Support\Integration\Security\OutboundUrlGuard;

/**
 * A fake resolver keeps this hermetic — no live DNS/network access
 * required to exercise the blocking logic.
 *
 * @param array<string, list<string>> $records
 */
function fakeResolver(array $records): callable
{
    return fn (string $host): array => $records[$host] ?? [];
}

it('accepts a hostname resolving to a public address', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver(['example.com' => ['93.184.216.34']]));

    expect($guard->isSafe('https://example.com/webhooks/inbound'))->toBeTrue();
});

it('rejects a non-http(s) scheme', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('ftp://example.com/x'))->toBeFalse()
        ->and($guard->isSafe('file:///etc/passwd'))->toBeFalse()
        ->and($guard->isSafe('gopher://example.com'))->toBeFalse();
});

it('rejects a malformed url', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('not a url'))->toBeFalse()
        ->and($guard->isSafe(''))->toBeFalse();
});

it('rejects localhost and .local hostnames without even consulting the resolver', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver(['localhost' => ['93.184.216.34']]));

    expect($guard->isSafe('http://localhost/x'))->toBeFalse()
        ->and($guard->isSafe('http://LOCALHOST/x'))->toBeFalse()
        ->and($guard->isSafe('http://my-service.local/x'))->toBeFalse();
});

it('rejects a literal loopback ip', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('http://127.0.0.1/x'))->toBeFalse()
        ->and($guard->isSafe('http://[::1]/x'))->toBeFalse();
});

it('rejects a literal private-range ip', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('http://10.0.0.5/x'))->toBeFalse()
        ->and($guard->isSafe('http://172.16.0.5/x'))->toBeFalse()
        ->and($guard->isSafe('http://192.168.1.5/x'))->toBeFalse();
});

it('rejects the cloud metadata link-local address, as a literal or via DNS', function (): void {
    $literal = new OutboundUrlGuard(fakeResolver([]));
    expect($literal->isSafe('http://169.254.169.254/latest/meta-data/'))->toBeFalse();

    $viaDns = new OutboundUrlGuard(fakeResolver(['attacker-controlled.example' => ['169.254.169.254']]));
    expect($viaDns->isSafe('http://attacker-controlled.example/x'))->toBeFalse();
});

it('accepts a literal public ip', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('http://8.8.8.8/x'))->toBeTrue();
});

it('rejects a hostname that fails to resolve', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([]));

    expect($guard->isSafe('http://this-host-does-not-exist.invalid/x'))->toBeFalse();
});

it('the default constructor (real system resolver) still rejects a literal internal ip, with no injection needed', function (): void {
    $guard = new OutboundUrlGuard;

    expect($guard->isSafe('http://127.0.0.1/x'))->toBeFalse();
});

it('rejects a hostname where only one of several resolved addresses is internal', function (): void {
    $guard = new OutboundUrlGuard(fakeResolver([
        'mixed.example' => ['93.184.216.34', '127.0.0.1'],
    ]));

    expect($guard->isSafe('http://mixed.example/x'))->toBeFalse();
});
