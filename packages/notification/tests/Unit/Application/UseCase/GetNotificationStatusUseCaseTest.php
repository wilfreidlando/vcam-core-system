<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\GetNotificationStatusUseCase;
use Core\Notification\Contracts\GetNotificationStatusCommand;
use Core\Notification\Domain\Exception\NotificationNotFound;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;

function seedNotification(InMemoryNotificationRepository $repo, string $id = 'notif-1', string $projectId = 'project-1'): Notification
{
    $notification = new Notification(
        id: $id,
        projectId: $projectId,
        channel: 'whatsapp',
        toPartyRef: 'user-1',
        contentType: 'text',
        idempotencyKey: 'idem-1',
        status: NotificationStatus::Sent,
        providerCode: 'fake-provider',
        providerMessageId: 'msg-1',
        errorMessage: null,
    );
    $repo->create($notification);

    return $notification;
}

it('returns the view for a notification owned by the requesting project', function (): void {
    $notifications = new InMemoryNotificationRepository;
    seedNotification($notifications);

    $useCase = new GetNotificationStatusUseCase($notifications);
    $view = $useCase->get(new GetNotificationStatusCommand('notif-1', 'project-1'));

    expect($view->id)->toBe('notif-1')
        ->and($view->status)->toBe(NotificationStatus::Sent);
});

it('rejects an unknown notification id', function (): void {
    $useCase = new GetNotificationStatusUseCase(new InMemoryNotificationRepository);

    $useCase->get(new GetNotificationStatusCommand('unknown', 'project-1'));
})->throws(NotificationNotFound::class);

it('rejects a notification that belongs to another project, with the same exception as not-found', function (): void {
    $notifications = new InMemoryNotificationRepository;
    seedNotification($notifications, 'notif-1', 'project-1');

    $useCase = new GetNotificationStatusUseCase($notifications);

    $useCase->get(new GetNotificationStatusCommand('notif-1', 'someone-elses-project'));
})->throws(NotificationNotFound::class);
