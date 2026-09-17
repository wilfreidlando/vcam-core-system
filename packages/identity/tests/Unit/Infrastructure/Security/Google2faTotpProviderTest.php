<?php

declare(strict_types=1);

use Core\Identity\Infrastructure\Security\Google2faTotpProvider;
use PragmaRX\Google2FA\Google2FA;

it('verifies a fresh code and returns a time step', function (): void {
    $google2fa = new Google2FA;
    $provider = new Google2faTotpProvider($google2fa);
    $secret = $google2fa->generateSecretKey();
    $code = $google2fa->getCurrentOtp($secret);

    $timestep = $provider->verify($secret, $code);

    expect($timestep)->toBeInt();
});

it('rejects a wrong code', function (): void {
    $google2fa = new Google2FA;
    $provider = new Google2faTotpProvider($google2fa);
    $secret = $google2fa->generateSecretKey();

    expect($provider->verify($secret, '000000'))->toBeFalse();
});

it('rejects replaying the same code against its own returned time step', function (): void {
    // RFC 6238 §5.2: a code already verified must not verify again for
    // the same or an older time step (review finding, Lot 6 follow-up).
    $google2fa = new Google2FA;
    $provider = new Google2faTotpProvider($google2fa);
    $secret = $google2fa->generateSecretKey();
    $code = $google2fa->getCurrentOtp($secret);

    $timestep = $provider->verify($secret, $code);
    expect($timestep)->toBeInt();
    assert(is_int($timestep));

    expect($provider->verify($secret, $code, $timestep))->toBeFalse();
});

it('accepts a later code against an earlier stored time step', function (): void {
    $google2fa = new Google2FA;
    $provider = new Google2faTotpProvider($google2fa);
    $secret = $google2fa->generateSecretKey();
    $code = $google2fa->getCurrentOtp($secret);

    // An artificially old "last verified" step must not block a
    // genuinely current, newer code.
    expect($provider->verify($secret, $code, 1))->toBeInt();
});
