<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\RevokeApiKey;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Support\Environment;

function keyToRevoke(): ApiKey
{
    return ApiKey::issue(
        id: 'key-1',
        projectId: 'project-1',
        environment: Environment::Live,
        prefix: 'pk_live_abcd1234',
        secretHash: ApiKey::hash('secret'),
        scopes: [],
        issuedAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );
}

it('revokes the key and records exactly one audit entry', function (): void {
    $apiKeys = new InMemoryApiKeyRepository;
    $apiKeys->save(keyToRevoke());
    $audit = new RecordingAuditLogger;
    $useCase = new RevokeApiKey($apiKeys, $audit, new FixedClock);

    $useCase->revoke(new RevokeApiKeyCommand('key-1'), actor: 'operator:1', correlationId: 'corr-1');

    $stored = $apiKeys->find('key-1');
    assert($stored instanceof ApiKey);

    expect($stored->isRevoked())->toBeTrue()
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('api_key.revoked');
});

it('is idempotent: revoking an already-revoked key adds no second audit entry', function (): void {
    $apiKeys = new InMemoryApiKeyRepository;
    $apiKeys->save(keyToRevoke());
    $audit = new RecordingAuditLogger;
    $useCase = new RevokeApiKey($apiKeys, $audit, new FixedClock);

    $useCase->revoke(new RevokeApiKeyCommand('key-1'), actor: 'operator:1', correlationId: 'corr-1');
    $useCase->revoke(new RevokeApiKeyCommand('key-1'), actor: 'operator:1', correlationId: 'corr-2');

    expect($audit->entries)->toHaveCount(1);
});

it('refuses to revoke an unknown key', function (): void {
    $useCase = new RevokeApiKey(new InMemoryApiKeyRepository, new RecordingAuditLogger, new FixedClock);

    $useCase->revoke(new RevokeApiKeyCommand('missing-key'), actor: 'operator:1', correlationId: null);
})->throws(ApiKeyNotFound::class);
