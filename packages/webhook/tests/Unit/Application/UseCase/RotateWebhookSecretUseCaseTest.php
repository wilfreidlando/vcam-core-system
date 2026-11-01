<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\PurgeExpiredWebhookSecretsUseCase;
use Core\Webhook\Application\UseCase\RevokePreviousWebhookSecretUseCase;
use Core\Webhook\Application\UseCase\RotateWebhookSecretUseCase;
use Core\Webhook\Contracts\RevokePreviousWebhookSecretCommand;
use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Core\Webhook\Domain\Exception\InvalidWebhookSecretRotation;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\FixedWebhookSecretGenerator;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use Core\Webhook\Tests\Unit\Fakes\PassthroughUnitOfWork;

function rotatableEndpoints(): InMemoryWebhookEndpointRepository
{
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.com/hooks', 'whsec_old', ['*'], true));

    return $endpoints;
}

it('replaces the secret and keeps the previous one for the overlap (ADR-0082 §5)', function (): void {
    $endpoints = rotatableEndpoints();
    $clock = new FixedClock(new DateTimeImmutable('2026-10-24T10:00:00Z'));

    $rotated = (new RotateWebhookSecretUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_new'), $clock, new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-1', 'endpoint-1', overlapHours: 6));

    $endpoint = $endpoints->find('endpoint-1');
    assert($endpoint instanceof WebhookEndpoint);

    expect($rotated->secret)->toBe('whsec_new')
        ->and($rotated->previousSecretExpiresAt->format(DATE_ATOM))->toBe('2026-10-24T16:00:00+00:00')
        ->and($endpoint->secret)->toBe('whsec_new')
        ->and($endpoint->previousSecretAt(new DateTimeImmutable('2026-10-24T15:59:59Z')))->toBe('whsec_old')
        ->and($endpoint->previousSecretAt(new DateTimeImmutable('2026-10-24T16:00:00Z')))->toBeNull();
});

it('answers "not found" for another project\'s endpoint', function (): void {
    (new RotateWebhookSecretUseCase(rotatableEndpoints(), new FixedWebhookSecretGenerator, new FixedClock, new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-2', 'endpoint-1'));
})->throws(WebhookEndpointNotFound::class);

it('refuses an overlap outside 1 to 72 hours', function (int $hours): void {
    (new RotateWebhookSecretUseCase(rotatableEndpoints(), new FixedWebhookSecretGenerator, new FixedClock, new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-1', 'endpoint-1', overlapHours: $hours));
})->with([0, 73])->throws(InvalidWebhookSecretRotation::class);

it('ends the overlap on demand, and says when there was nothing to end', function (): void {
    $endpoints = rotatableEndpoints();
    $clock = new FixedClock(new DateTimeImmutable('2026-10-24T10:00:00Z'));
    (new RotateWebhookSecretUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_new'), $clock, new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-1', 'endpoint-1'));

    $revoke = new RevokePreviousWebhookSecretUseCase($endpoints, $clock, new PassthroughUnitOfWork);

    expect($revoke->revoke(new RevokePreviousWebhookSecretCommand('project-1', 'endpoint-1')))->toBeTrue()
        ->and($endpoints->find('endpoint-1')?->previousSecret)->toBeNull()
        ->and($endpoints->find('endpoint-1')?->secret)->toBe('whsec_new')
        ->and($revoke->revoke(new RevokePreviousWebhookSecretCommand('project-1', 'endpoint-1')))->toBeFalse();
});

it('erases previous secrets once their overlap has ended, and only those', function (): void {
    $endpoints = rotatableEndpoints();
    $endpoints->create(new WebhookEndpoint('endpoint-2', 'project-1', 'https://example.com/other', 'whsec_a', ['*'], true));
    $start = new DateTimeImmutable('2026-10-24T10:00:00Z');
    (new RotateWebhookSecretUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_b'), new FixedClock($start), new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-1', 'endpoint-1', overlapHours: 1));
    (new RotateWebhookSecretUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_c'), new FixedClock($start), new PassthroughUnitOfWork))
        ->rotate(new RotateWebhookSecretCommand('project-1', 'endpoint-2', overlapHours: 48));

    $purged = (new PurgeExpiredWebhookSecretsUseCase($endpoints, new FixedClock($start->modify('+2 hours'))))->purge();

    expect($purged)->toBe(1)
        ->and($endpoints->find('endpoint-1')?->previousSecret)->toBeNull()
        ->and($endpoints->find('endpoint-2')?->previousSecret)->toBe('whsec_a');
});
