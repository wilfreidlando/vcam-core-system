<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\ListApiKeys;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;

it('lists a project\'s own keys, newest first, never carrying the secret', function (): void {
    $apiKeys = new InMemoryApiKeyRepository;
    $apiKeys->save(ApiKey::issue('key-1', 'project-1', 'pk_a', ApiKey::hash('s1'), ['payments:write'], new DateTimeImmutable('2026-09-15T10:00:00Z')));
    $apiKeys->save(ApiKey::issue('key-2', 'project-1', 'pk_live_b', ApiKey::hash('s2'), [], new DateTimeImmutable('2026-09-15T10:00:00Z')));
    $apiKeys->save(ApiKey::issue('key-3', 'someone-elses-project', 'pk_c', ApiKey::hash('s3'), [], new DateTimeImmutable('2026-09-15T10:00:00Z')));

    $useCase = new ListApiKeys($apiKeys);

    $views = $useCase->list(new ListApiKeysCommand('project-1'));

    expect($views)->toHaveCount(2)
        ->and(array_map(fn ($v) => $v->id, $views))->toEqualCanonicalizing(['key-1', 'key-2'])
        ->and($views[0]->prefix)->not->toBeEmpty();
});

it('reflects the revoked flag', function (): void {
    $apiKeys = new InMemoryApiKeyRepository;
    $key = ApiKey::issue('key-1', 'project-1', 'pk_a', ApiKey::hash('s1'), [], new DateTimeImmutable('2026-09-15T10:00:00Z'));
    $key->revoke(new DateTimeImmutable('2026-09-16T10:00:00Z'));
    $apiKeys->save($key);

    $useCase = new ListApiKeys($apiKeys);

    $views = $useCase->list(new ListApiKeysCommand('project-1'));

    expect($views[0]->revoked)->toBeTrue();
});
