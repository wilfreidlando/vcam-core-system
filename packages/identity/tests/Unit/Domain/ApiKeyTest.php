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
