<?php

declare(strict_types=1);

use Core\Identity\Domain\Exception\InvalidApiKey;
use Core\Identity\Domain\Model\ApiKeyToken;

it('composes and re-parses to the same value', function (): void {
    $token = ApiKeyToken::compose('pk_sandbox_abcd1234', 'the-secret-part');

    expect(ApiKeyToken::parse($token->value())->prefix)->toBe('pk_sandbox_abcd1234')
        ->and(ApiKeyToken::parse($token->value())->secret)->toBe('the-secret-part');
});

it('splits on the first separator, so a secret may itself contain dots', function (): void {
    $token = ApiKeyToken::parse('pk_sandbox_abcd1234.part.with.dots');

    expect($token->prefix)->toBe('pk_sandbox_abcd1234')
        ->and($token->secret)->toBe('part.with.dots');
});

it('rejects a token with no separator', function (): void {
    ApiKeyToken::parse('no-separator-here');
})->throws(InvalidApiKey::class);

it('rejects a token with nothing before the separator', function (): void {
    ApiKeyToken::parse('.secret-only');
})->throws(InvalidApiKey::class);

it('rejects a token with nothing after the separator', function (): void {
    ApiKeyToken::parse('prefix-only.');
})->throws(InvalidApiKey::class);
