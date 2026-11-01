<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\ResolveProjectFromBearerToken;
use Core\Identity\Domain\Exception\ApiKeyOriginNotAllowed;
use Core\Identity\Domain\Exception\InvalidApiKey;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;

/**
 * @param list<string> $allowedCidrs
 * @return array{0: ResolveProjectFromBearerToken, 1: InMemoryApiKeyRepository}
 */
function seededApiKeyResolver(string $projectStatus = 'active', array $allowedCidrs = [], ?DateTimeImmutable $expiresAt = null): array
{
    $projects = new InMemoryProjectRepository;
    $apiKeys = new InMemoryApiKeyRepository;

    $projects->save(new Project('project-1', 'org-1', 'Test Project', $projectStatus));

    $apiKeys->save(ApiKey::issue(
        id: 'key-1',
        projectId: 'project-1',
        prefix: 'pk_live_abcd1234',
        secretHash: ApiKey::hash('correct-secret'),
        scopes: ['payments:write'],
        issuedAt: new DateTimeImmutable('2026-09-15T09:00:00Z'),
        allowedCidrs: $allowedCidrs,
        expiresAt: $expiresAt,
    ));

    return [new ResolveProjectFromBearerToken($apiKeys, $projects, new FixedClock), $apiKeys];
}

it('resolves the project context for a valid token', function (): void {
    [$resolver] = seededApiKeyResolver();

    $context = $resolver('pk_live_abcd1234.correct-secret');

    expect($context->projectId)->toBe('project-1')
        ->and($context->organizationId)->toBe('org-1')
        ->and($context->scopes)->toBe(['payments:write']);
});

it('rejects a well-formed token with an unknown prefix', function (): void {
    [$resolver] = seededApiKeyResolver();

    $resolver('pk_live_unknown0.some-secret');
})->throws(InvalidApiKey::class);

it('rejects the right prefix with the wrong secret', function (): void {
    [$resolver] = seededApiKeyResolver();

    $resolver('pk_live_abcd1234.wrong-secret');
})->throws(InvalidApiKey::class);

it('rejects a malformed token', function (): void {
    [$resolver] = seededApiKeyResolver();

    $resolver('not-a-valid-token');
})->throws(InvalidApiKey::class);

it('rejects a revoked key even with the correct secret', function (): void {
    [$resolver, $apiKeys] = seededApiKeyResolver();

    $key = $apiKeys->find('key-1');
    assert($key instanceof ApiKey);
    $key->revoke(new DateTimeImmutable('2026-09-15T11:00:00Z'));
    $apiKeys->save($key);

    $resolver('pk_live_abcd1234.correct-secret');
})->throws(InvalidApiKey::class);

it('rejects a valid key belonging to an inactive project', function (): void {
    [$resolver] = seededApiKeyResolver(projectStatus: 'suspended');

    $resolver('pk_live_abcd1234.correct-secret');
})->throws(ProjectNotActive::class);

it('rejects an expired key with the same 401 as an unknown one (ADR-0082 §3)', function (): void {
    // FixedClock is 2026-09-15T10:00:00Z.
    [$resolver] = seededApiKeyResolver(expiresAt: new DateTimeImmutable('2026-09-15T10:00:00Z'));

    $resolver('pk_live_abcd1234.correct-secret');
})->throws(InvalidApiKey::class);

it('accepts a restricted key from inside its blocks and records the address', function (): void {
    [$resolver, $apiKeys] = seededApiKeyResolver(allowedCidrs: ['203.0.113.0/24', '2001:db8::/32']);

    $context = $resolver('pk_live_abcd1234.correct-secret', '2001:db8:0:1::5');

    expect($context->apiKeyId)->toBe('key-1')
        ->and($apiKeys->touches)->toBe([['id' => 'key-1', 'ip' => '2001:db8:0:1::5']]);
});

it('refuses a restricted key from outside its blocks, or from an unknown address (ADR-0082 §4)', function (?string $ip): void {
    [$resolver, $apiKeys] = seededApiKeyResolver(allowedCidrs: ['203.0.113.0/24']);

    $refused = null;
    try {
        $resolver('pk_live_abcd1234.correct-secret', $ip);
    } catch (ApiKeyOriginNotAllowed $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(ApiKeyOriginNotAllowed::class)
        ->and($refused?->apiKeyId)->toBe('key-1')
        ->and($refused?->clientIp)->toBe($ip)
        ->and($apiKeys->touches)->toBe([]);
})->with(['198.51.100.7', null]);
