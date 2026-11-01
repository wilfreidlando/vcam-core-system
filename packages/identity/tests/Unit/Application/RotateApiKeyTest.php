<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\IssueApiKey;
use Core\Identity\Application\UseCase\RotateApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\RotateApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyAlreadyRotated;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\FixedApiKeySecretGenerator;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

/**
 * @return array{0: RotateApiKey, 1: IssueApiKey, 2: InMemoryApiKeyRepository, 3: RecordingAuditLogger}
 */
function rotationFixture(int $maxKeys = 1): array
{
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-1', 'Test Project'));
    $apiKeys = new InMemoryApiKeyRepository;
    $audit = new RecordingAuditLogger;
    $clock = new FixedClock(new DateTimeImmutable('2026-10-24T10:00:00Z'));

    $issue = new IssueApiKey($projects, $apiKeys, new FixedApiKeySecretGenerator, new SequentialIdentifier, $audit, $clock, new PassthroughUnitOfWork, $maxKeys);

    return [new RotateApiKey($apiKeys, $issue, $clock), $issue, $apiKeys, $audit];
}

it('issues a replacement with the same name, scopes and network, and retires the old key after the grace period', function (): void {
    [$rotate, $issue, $apiKeys, $audit] = rotationFixture(maxKeys: 1);
    $old = $issue->issue(new IssueApiKeyCommand('project-1', ['payments:read'], name: 'Serveur', allowedCidrs: ['198.51.100.0/24']), actor: 'operator:1', correlationId: null);

    // A rotation never counts against the limit: the project is already at 1/1.
    $new = $rotate->rotate(new RotateApiKeyCommand($old->apiKeyId, projectId: 'project-1', graceDays: 3), actor: 'operator:1', correlationId: null);

    $oldKey = $apiKeys->find($old->apiKeyId);
    $newKey = $apiKeys->find($new->apiKeyId);
    assert($oldKey instanceof ApiKey && $newKey instanceof ApiKey);

    expect($newKey->name)->toBe('Serveur')
        ->and($newKey->scopes())->toBe(['payments:read'])
        ->and($newKey->allowedCidrs())->toBe(['198.51.100.0/24'])
        ->and($newKey->expiresAt())->toBeNull()
        ->and($oldKey->replacedByApiKeyId())->toBe($new->apiKeyId)
        ->and($oldKey->expiresAt()?->format(DATE_ATOM))->toBe('2026-10-27T10:00:00+00:00')
        ->and($oldKey->isUsableAt(new DateTimeImmutable('2026-10-27T09:59:59Z')))->toBeTrue()
        ->and($oldKey->isUsableAt(new DateTimeImmutable('2026-10-27T10:00:00Z')))->toBeFalse()
        ->and($audit->entries[1]->action)->toBe('api_key.rotated');
});

it('rotates a key only once', function (): void {
    [$rotate, $issue] = rotationFixture();
    $old = $issue->issue(new IssueApiKeyCommand('project-1', []), actor: 'operator:1', correlationId: null);
    $rotate->rotate(new RotateApiKeyCommand($old->apiKeyId), actor: 'operator:1', correlationId: null);

    $rotate->rotate(new RotateApiKeyCommand($old->apiKeyId), actor: 'operator:1', correlationId: null);
})->throws(ApiKeyAlreadyRotated::class);

it('refuses a grace period outside 1 to 30 days', function (int $days): void {
    [$rotate, $issue] = rotationFixture();
    $old = $issue->issue(new IssueApiKeyCommand('project-1', []), actor: 'operator:1', correlationId: null);

    $rotate->rotate(new RotateApiKeyCommand($old->apiKeyId, graceDays: $days), actor: 'operator:1', correlationId: null);
})->with([0, 31])->throws(InvalidApiKeySettings::class);

it('answers "not found" for a key of another project', function (): void {
    [$rotate, $issue] = rotationFixture();
    $old = $issue->issue(new IssueApiKeyCommand('project-1', []), actor: 'operator:1', correlationId: null);

    $rotate->rotate(new RotateApiKeyCommand($old->apiKeyId, projectId: 'project-2'), actor: 'operator:1', correlationId: null);
})->throws(ApiKeyNotFound::class);

it('never pushes back an expiry the old key already had', function (): void {
    $key = ApiKey::issue('key-1', 'project-1', 'pk_a', ApiKey::hash('s'), [], new DateTimeImmutable('2026-10-24T10:00:00Z'), expiresAt: new DateTimeImmutable('2026-10-25T10:00:00Z'));

    $key->markReplacedBy('key-2', new DateTimeImmutable('2026-10-31T10:00:00Z'));

    expect($key->expiresAt()?->format(DATE_ATOM))->toBe('2026-10-25T10:00:00+00:00');
});

it('caps chained rotations: a replaced key still counts until it retires', function (): void {
    [$rotate, $issue] = rotationFixture(maxKeys: 1);
    $a = $issue->issue(new IssueApiKeyCommand('project-1', []), actor: 'operator:1', correlationId: null);

    // One over the limit while A finishes its grace period…
    $b = $rotate->rotate(new RotateApiKeyCommand($a->apiKeyId), actor: 'operator:1', correlationId: null);

    // …never two: A, B and C would all authenticate at once.
    $rotate->rotate(new RotateApiKeyCommand($b->apiKeyId), actor: 'operator:1', correlationId: null);
})->throws(Core\Identity\Domain\Exception\ApiKeyLimitReached::class);
