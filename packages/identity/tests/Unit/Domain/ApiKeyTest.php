<?php

declare(strict_types=1);

use Core\Identity\Domain\Model\ApiKey;
use Core\Support\Environment;

/**
 * @param list<string> $scopes
 */
function issuedApiKey(string $secret = 'correct-secret', array $scopes = ['payments:write']): ApiKey
{
    return ApiKey::issue(
        id: 'key-1',
        projectId: 'project-1',
        environment: Environment::Sandbox,
        prefix: 'pk_sandbox_abcd1234',
        secretHash: ApiKey::hash($secret),
        scopes: $scopes,
        issuedAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );
}

it('matches the exact secret it was issued with', function (): void {
    expect(issuedApiKey('correct-secret')->matchesSecret('correct-secret'))->toBeTrue();
});

it('rejects any other secret', function (): void {
    expect(issuedApiKey('correct-secret')->matchesSecret('wrong-secret'))->toBeFalse();
});

it('never stores the secret itself as its hash', function (): void {
    $apiKey = issuedApiKey('correct-secret');

    expect($apiKey->keyHash())->not->toBe('correct-secret');
});

it('is not revoked when issued', function (): void {
    expect(issuedApiKey()->isRevoked())->toBeFalse();
});

it('is revoked after revoke() and keeps the first revocation time', function (): void {
    $apiKey = issuedApiKey();
    $first = new DateTimeImmutable('2026-09-15T11:00:00Z');
    $second = new DateTimeImmutable('2026-09-15T12:00:00Z');

    $apiKey->revoke($first);
    $apiKey->revoke($second);

    expect($apiKey->isRevoked())->toBeTrue()
        ->and($apiKey->revokedAt())->toEqual($first);
});

it('exposes the scopes it was issued with', function (): void {
    $apiKey = issuedApiKey(scopes: ['payments:write', 'wallets:read']);

    expect($apiKey->hasScope('payments:write'))->toBeTrue()
        ->and($apiKey->hasScope('ledger:admin'))->toBeFalse()
        ->and($apiKey->scopes())->toBe(['payments:write', 'wallets:read']);
});

it('treats the * scope as a wildcard covering every concrete scope', function (): void {
    // Audit finding (bbbfc53-adjacent): a Project key issued with
    // `--scope='*'` (the ergonomic shape every Core-issued key uses,
    // per ADR-0044) used to fail hasScope() for any concrete scope
    // — `whoami` returned `scopes: ["*"]` but POST /payments still
    // returned 403 "The payments:write scope is required". The wildcard
    // was a lie. Mirroring `OperatorRole::hasPermission()`, which
    // already handled `*` correctly, removes the lie.
    $wildcardKey = issuedApiKey(scopes: ['*']);

    expect($wildcardKey->hasScope('payments:write'))->toBeTrue()
        ->and($wildcardKey->hasScope('notifications:read'))->toBeTrue()
        ->and($wildcardKey->hasScope('a:scope:that:does:not:exist:yet'))->toBeTrue()
        ->and($wildcardKey->scopes())->toBe(['*']);
});

it('does not treat * as a wildcard when it appears only as a substring', function (): void {
    // Defensive: a scope like 'payments:*' or '*:write' is NOT the
    // wildcard — only the literal exact-match '*' is. (A future feature
    // could add globs; today it would be a footgun — a caller could
    // accidentally mint a key with `'payments:*'` thinking it's
    // "all payment scopes" and silently lose webhooks:write.)
    $narrowKey = issuedApiKey(scopes: ['payments:*', '*:write', 'payments:write']);

    expect($narrowKey->hasScope('payments:write'))->toBeTrue()
        ->and($narrowKey->hasScope('payments:read'))->toBeFalse()
        ->and($narrowKey->hasScope('webhooks:write'))->toBeFalse()
        ->and($narrowKey->hasScope('anything:else'))->toBeFalse();
});
