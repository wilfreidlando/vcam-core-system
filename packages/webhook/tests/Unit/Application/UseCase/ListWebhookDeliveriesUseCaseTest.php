<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\ListWebhookDeliveriesUseCase;
use Core\Webhook\Contracts\ListWebhookDeliveriesCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;

function deliveryWithStatus(string $id, WebhookDeliveryStatus $status, string $projectId = 'project-a'): WebhookDelivery
{
    return new WebhookDelivery(
        id: $id,
        endpointId: 'endpoint-1',
        projectId: $projectId,
        eventId: 'event-'.$id,
        eventType: 'payment.status_changed',
        payload: [],
        status: $status,
        attempts: $status === WebhookDeliveryStatus::Dead ? 8 : 1,
        nextRetryAt: null,
        lastAttemptAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        lastError: $status === WebhookDeliveryStatus::Dead ? 'timeout' : null,
        deliveredAt: $status === WebhookDeliveryStatus::Delivered ? new DateTimeImmutable('2026-09-15T10:00:05Z') : null,
    );
}

it('lists across every status when none is given', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    $repository->create(deliveryWithStatus('d1', WebhookDeliveryStatus::Pending));
    $repository->create(deliveryWithStatus('d2', WebhookDeliveryStatus::Delivered));
    $repository->create(deliveryWithStatus('d3', WebhookDeliveryStatus::Dead));

    $useCase = new ListWebhookDeliveriesUseCase($repository);

    $page = $useCase->list(new ListWebhookDeliveriesCommand);

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(3)
        ->and(array_map(fn ($v) => $v->status, $page->items))->toEqualCanonicalizing(['pending', 'delivered', 'dead']);
});

it('narrows to one status when given', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    $repository->create(deliveryWithStatus('d1', WebhookDeliveryStatus::Pending));
    $repository->create(deliveryWithStatus('d2', WebhookDeliveryStatus::Delivered));

    $useCase = new ListWebhookDeliveriesUseCase($repository);

    $page = $useCase->list(new ListWebhookDeliveriesCommand(status: 'delivered'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('d2')
        ->and($page->items[0]->status)->toBe('delivered')
        ->and($page->items[0]->deliveredAt)->not->toBeNull();
});

it('silently ignores an unrecognised status, same convention as ListPaymentsCommand', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    $repository->create(deliveryWithStatus('d1', WebhookDeliveryStatus::Pending));

    $useCase = new ListWebhookDeliveriesUseCase($repository);

    $page = $useCase->list(new ListWebhookDeliveriesCommand(status: 'not-a-real-status'));

    expect($page->total)->toBe(1);
});

it('narrows to one project when given', function (): void {
    $repository = new InMemoryWebhookDeliveryRepository;
    $repository->create(deliveryWithStatus('d1', WebhookDeliveryStatus::Pending, 'project-a'));
    $repository->create(deliveryWithStatus('d2', WebhookDeliveryStatus::Pending, 'project-b'));

    $useCase = new ListWebhookDeliveriesUseCase($repository);

    $page = $useCase->list(new ListWebhookDeliveriesCommand(projectId: 'project-a'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('d1');
});
