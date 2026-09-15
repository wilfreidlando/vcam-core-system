<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ListDeadWebhookDeliveriesUseCase;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;

function deadDelivery(string $id, string $projectId): WebhookDelivery
{
    return new WebhookDelivery(
        id: $id,
        endpointId: 'endpoint-1',
        projectId: $projectId,
        eventId: 'event-'.$id,
        eventType: 'payment.status_changed',
        payload: [],
        status: WebhookDeliveryStatus::Dead,
        attempts: 8,
        nextRetryAt: null,
        lastAttemptAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        lastError: 'timeout',
        deliveredAt: null,
    );
}

it('lists only dead deliveries, optionally scoped to a project', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    $repository->create(deadDelivery('d1', 'project-a'));
    $repository->create(deadDelivery('d2', 'project-b'));
    $repository->create(new WebhookDelivery(
        id: 'd3', endpointId: 'endpoint-1', projectId: 'project-a', eventId: 'event-d3',
        eventType: 'payment.status_changed', payload: [], status: WebhookDeliveryStatus::Delivered,
        attempts: 1, nextRetryAt: null, lastAttemptAt: null, lastError: null, deliveredAt: new DateTimeImmutable,
    ));

    $useCase = new ListDeadWebhookDeliveriesUseCase($repository);

    $all = $useCase->list(new ListDeadWebhookDeliveriesCommand);
    $scoped = $useCase->list(new ListDeadWebhookDeliveriesCommand(projectId: 'project-a'));

    expect($all->total)->toBe(2)
        ->and($scoped->total)->toBe(1)
        ->and($scoped->items[0]->id)->toBe('d1')
        ->and($scoped->items[0]->attempts)->toBe(8)
        ->and($scoped->items[0]->lastError)->toBe('timeout');
});

it('paginates', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    for ($i = 0; $i < 3; $i++) {
        $repository->create(deadDelivery('d'.$i, 'project-a'));
    }

    $useCase = new ListDeadWebhookDeliveriesUseCase($repository);
    $page = $useCase->list(new ListDeadWebhookDeliveriesCommand(page: 1, perPage: 2));

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2)
        ->and($page->page)->toBe(1)
        ->and($page->perPage)->toBe(2);
});
