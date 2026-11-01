<?php

declare(strict_types=1);

use Core\Support\Privacy\HmacBlindIndex;
use Core\Support\Privacy\MissingPersonalDataKey;
use Core\Support\Privacy\PersonalDataKind;

function blindIndexWithKey(string $key): HmacBlindIndex
{
    return new HmacBlindIndex($key);
}

it('gives the same index for the same value, whatever its presentation', function (): void {
    $index = blindIndexWithKey('base64:'.base64_encode(str_repeat('k', 32)));

    expect($index->of('Alice@Domaine.cm', PersonalDataKind::Email))
        ->toBe($index->of('  alice@domaine.cm  ', PersonalDataKind::Email));
});

it('normalises a phone number to its digits', function (): void {
    $index = blindIndexWithKey('base64:'.base64_encode(str_repeat('k', 32)));

    expect($index->of('+237 6 90 00 11 12', PersonalDataKind::Phone))
        ->toBe($index->of('+237690001112', PersonalDataKind::Phone));
});

it('gives different indexes for different values', function (): void {
    $index = blindIndexWithKey('base64:'.base64_encode(str_repeat('k', 32)));

    expect($index->of('alice@domaine.cm', PersonalDataKind::Email))
        ->not->toBe($index->of('bob@domaine.cm', PersonalDataKind::Email));
});

it('never leaks the value itself', function (): void {
    $index = blindIndexWithKey('base64:'.base64_encode(str_repeat('k', 32)));

    $digest = $index->of('alice@domaine.cm', PersonalDataKind::Email);

    expect($digest)->toHaveLength(64)
        ->and($digest)->not->toContain('alice')
        ->and($digest)->toMatch('/^[0-9a-f]{64}$/');
});

it('separates the kinds, so an email and a phone never share an index', function (): void {
    $index = blindIndexWithKey('base64:'.base64_encode(str_repeat('k', 32)));

    expect($index->of('12345678', PersonalDataKind::Phone))
        ->not->toBe($index->of('12345678', PersonalDataKind::Text));
});

it('refuses to work without a key, rather than indexing with a guessable one', function (): void {
    blindIndexWithKey('')->of('alice@domaine.cm', PersonalDataKind::Email);
})->throws(MissingPersonalDataKey::class);

it('refuses a key that is too short', function (): void {
    blindIndexWithKey('base64:'.base64_encode('short'))->of('x', PersonalDataKind::Text);
})->throws(MissingPersonalDataKey::class);
