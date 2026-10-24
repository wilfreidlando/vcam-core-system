<?php

declare(strict_types=1);

use Core\Support\Privacy\EncrypterPersonalDataCipher;
use Core\Support\Privacy\MissingPersonalDataKey;
use Core\Support\Privacy\UndecryptablePersonalData;

function cipherWithKeys(string $keys): EncrypterPersonalDataCipher
{
    return new EncrypterPersonalDataCipher($keys);
}

function aKeyring(): string
{
    return 'v1:base64:'.base64_encode(str_repeat('a', 32));
}

it('round-trips a value and never keeps it in clear', function (): void {
    $cipher = cipherWithKeys(aKeyring());

    $encrypted = $cipher->encrypt('alice@domaine.cm');

    expect($encrypted)->not->toContain('alice@domaine.cm')
        ->and($encrypted)->toStartWith('pii:v1:')
        ->and($cipher->decrypt($encrypted))->toBe('alice@domaine.cm');
});

it('produces a different ciphertext every time (random IV)', function (): void {
    $cipher = cipherWithKeys(aKeyring());

    expect($cipher->encrypt('alice@domaine.cm'))->not->toBe($cipher->encrypt('alice@domaine.cm'));
});

it('decrypts with an older key version while the rotation runs, and flags it for re-encryption', function (): void {
    $old = cipherWithKeys('v1:base64:'.base64_encode(str_repeat('a', 32)));
    $encrypted = $old->encrypt('alice@domaine.cm');

    $rotated = cipherWithKeys('v2:base64:'.base64_encode(str_repeat('b', 32)).',v1:base64:'.base64_encode(str_repeat('a', 32)));

    expect($rotated->decrypt($encrypted))->toBe('alice@domaine.cm')
        ->and($rotated->needsReEncryption($encrypted))->toBeTrue()
        ->and($rotated->needsReEncryption($rotated->encrypt('x')))->toBeFalse();
});

it('refuses a value encrypted with a key version nobody configured', function (): void {
    $encrypted = cipherWithKeys('v1:base64:'.base64_encode(str_repeat('a', 32)))->encrypt('x');

    cipherWithKeys('v2:base64:'.base64_encode(str_repeat('b', 32)))->decrypt($encrypted);
})->throws(UndecryptablePersonalData::class);

it('refuses a tampered value', function (): void {
    $cipher = cipherWithKeys(aKeyring());
    $encrypted = $cipher->encrypt('alice@domaine.cm');
    $tampered = substr($encrypted, 0, -4).strrev(substr($encrypted, -4));

    $cipher->decrypt($tampered);
})->throws(UndecryptablePersonalData::class);

it('refuses a value that is not personal data encrypted by this system', function (string $value): void {
    cipherWithKeys(aKeyring())->decrypt($value);
})->with(['alice@domaine.cm', 'pii:v1', 'pii:x:abc', 'pii:v1:', 'other:v1:abc'])->throws(UndecryptablePersonalData::class);

it('refuses to work without a configured key, rather than storing anything in clear', function (): void {
    cipherWithKeys('')->encrypt('alice@domaine.cm');
})->throws(MissingPersonalDataKey::class);

it('refuses a keyring that is not 32 bytes per version', function (string $configured): void {
    cipherWithKeys($configured)->encrypt('x');
})->with([
    'v1:base64:'.base64_encode('short'),
    'v1:notbase64',
    'v1',
    'v1:base64:'.base64_encode(str_repeat('a', 32)).',v1:base64:'.base64_encode(str_repeat('b', 32)),
])->throws(MissingPersonalDataKey::class);
