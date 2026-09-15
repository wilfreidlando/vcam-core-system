<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\HandleNotificationStatusChangedUseCase;
use Core\Notification\Contracts\Events\NotificationStatusChanged;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\FixedClock;
use Core\Notification\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Notification\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Notification\Tests\Unit\Fakes\SequentialIdentifier;
use Psr\Log\NullLogger;

/**
 * @return array{0: HandleNotificationStatusChangedUseCase, 1: InMemoryNotificationRepository, 2: InMemoryEventOutbox}
 */
function makeStatusHandler(): array
{
    $notifications = new InMemoryNotificationRepository;
    $outbox = new InMemoryEventOutbox;

    $handler = new HandleNotificationStatusChangedUseCase(
        new PassthroughUnitOfWork,
        $notifications,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
    );

    return [$handler, $notifications, $outbox];
}

function pinnedNotification(NotificationStatus $status = NotificationStatus::Sent): Notification
{
    return new Notification(
        id: 'notif-1',
        projectId: 'project-1',
        channel: 'whatsapp',
        toPartyRef: 'user-1',
        templateKey: 'booking_confirmed',
        idempotencyKey: 'idem-1',
        status: $status,
        providerCode: 'whatsapp-selfhosted',
        providerMessageId: 'msg-1',
        errorMessage: null,
    );
}

function notificationStatusChangedEvent(NotificationStatus $status, ?string $reason = null): NotificationStatusChanged
{
    return new NotificationStatusChanged(
        providerCode: 'whatsapp-selfhosted',
        providerMessageId: 'msg-1',
        status: $status,
        reason: $reason,
    );
}

it('applies a valid transition and publishes the matching canonical event', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    $notifications->create(pinnedNotification(NotificationStatus::Sent));

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Delivered));

    expect($notifications->find('notif-1')?->status)->toBe(NotificationStatus::Delivered)
        ->and($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('notification.delivered');
});

it('publishes notification.read when a delivered message is read', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    $notifications->create(pinnedNotification(NotificationStatus::Delivered));

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Read));

    expect($outbox->events[0]->type)->toBe('notification.read');
});

it('publishes notification.failed on a definitive failure', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    $notifications->create(pinnedNotification(NotificationStatus::Sent));

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Failed, 'invalid number'));

    expect($notifications->find('notif-1')?->status)->toBe(NotificationStatus::Failed)
        ->and($outbox->events[0]->type)->toBe('notification.failed');
});

it('is idempotent: a replayed callback after the notification already settled has no second effect', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    $notifications->create(pinnedNotification(NotificationStatus::Delivered));

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Read));
    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Read));

    expect($outbox->events)->toHaveCount(1, 'the outbox must receive exactly one event');
});

it('ignores an out-of-order transition (e.g. read arriving before delivered) without throwing', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    $notifications->create(pinnedNotification(NotificationStatus::Sent));

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Read));

    expect($notifications->find('notif-1')?->status)->toBe(NotificationStatus::Sent, 'the invalid transition is ignored, not applied')
        ->and($outbox->events)->toHaveCount(0);
});

it('ignores a callback for a provider message this Core never sent', function (): void {
    [$handler, $notifications, $outbox] = makeStatusHandler();
    // No notification seeded — nothing matches (providerCode, providerMessageId).

    $handler->handle(notificationStatusChangedEvent(NotificationStatus::Delivered));

    expect($notifications->count())->toBe(0)
        ->and($outbox->events)->toHaveCount(0);
});
