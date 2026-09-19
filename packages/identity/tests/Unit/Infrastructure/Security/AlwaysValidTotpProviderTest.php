<?php

declare(strict_types=1);

use Core\Identity\Infrastructure\Security\AlwaysValidTotpProvider;

it('accepts any code for any secret', function (): void {
    $provider = new AlwaysValidTotpProvider;

    expect($provider->verify('any-secret-at-all', '000000'))->toBeInt();
    expect($provider->verify('another-secret', 'not-even-numeric'))->toBeInt();
});

it('never rejects, even against a very recent stored time step', function (): void {
    $provider = new AlwaysValidTotpProvider;

    $first = $provider->verify('secret', '123456');

    expect($provider->verify('secret', '123456', $first))->toBeInt();
});

it('always returns the same fixed dev code, never the real secret', function (): void {
    $provider = new AlwaysValidTotpProvider;

    expect($provider->currentCode('whatever-secret'))->toBe('000000');
});
