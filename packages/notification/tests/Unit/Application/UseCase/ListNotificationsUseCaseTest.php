<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\ListNotificationsUseCase;
use Core\Notification\Contracts\ListNotificationsCommand;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Support\Access\AccessScope;

function listableNotification(string $id, string $projectId, NotificationStatus $status = NotificationStatus::Sent): Notification
{
    return new Notification(
        id: $id,
        projectId: $projectId,
        channel: 'whatsapp',
        toPartyRef: 'party-1',
        contentType: 'text',
        idempotencyKey: 'idem-'.$id,
        status: $status,
        providerCode: 'whatsapp-waha',
        providerMessageId: null,
        errorMessage: null,
    );
}

it('lists only the requesting project\'s notifications', function (): void {
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(listableNotification('n1', 'project-a'));
    $notifications->create(listableNotification('n2', 'project-b'));

    $useCase = new ListNotificationsUseCase($notifications);
    $page = $useCase->list(new ListNotificationsCommand('project-a'), AccessScope::platform());

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('n1');
});

it('filters by canonical status', function (): void {
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(listableNotification('n1', 'project-a', NotificationStatus::Sent));
    $notifications->create(listableNotification('n2', 'project-a', NotificationStatus::Failed));

    $useCase = new ListNotificationsUseCase($notifications);
    $page = $useCase->list(new ListNotificationsCommand('project-a', status: 'failed'), AccessScope::platform());

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('n2');
});

it('lists nothing for a project outside the account\'s scope', function (): void {
    // ADR-0069 §2 — the port refuses the tenant, not the console.
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(listableNotification('n1', 'project-b'));

    $useCase = new ListNotificationsUseCase($notifications);
    $page = $useCase->list(new ListNotificationsCommand('project-b'), AccessScope::forProject('project-a'));

    expect($page->total)->toBe(0)
        ->and($page->items)->toBe([]);
});

it('lists nothing when the account sees no project at all', function (): void {
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(listableNotification('n1', 'project-a'));

    $useCase = new ListNotificationsUseCase($notifications);
    $page = $useCase->list(new ListNotificationsCommand('project-a'), AccessScope::none());

    expect($page->total)->toBe(0);
});
