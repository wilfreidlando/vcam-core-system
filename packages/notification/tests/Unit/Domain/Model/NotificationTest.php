<?php

declare(strict_types=1);

use Core\Notification\Domain\Exception\InvalidNotificationStatusTransition;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;

function makeNotification(NotificationStatus $status = NotificationStatus::Queued): Notification
{
    return new Notification(
        id: 'notif-1',
        projectId: 'project-1',
        channel: 'whatsapp',
        toPartyRef: 'user-1',
        contentType: 'text',
        idempotencyKey: 'idem-1',
        status: $status,
        providerCode: null,
        providerMessageId: null,
        errorMessage: null,
    );
}

it('withStatusChange returns a new instance for a valid transition', function (): void {
    $notification = makeNotification();

    $updated = $notification->withStatusChange(NotificationStatus::Sent, null);

    expect($updated)->not->toBe($notification)
        ->and($updated->status)->toBe(NotificationStatus::Sent)
        ->and($notification->status)->toBe(NotificationStatus::Queued, 'the original instance is untouched');
});

it('withStatusChange rejects an invalid transition', function (): void {
    $notification = makeNotification(NotificationStatus::Queued);

    $notification->withStatusChange(NotificationStatus::Read, null);
})->throws(InvalidNotificationStatusTransition::class);

it('withStatusChange carries the reason into errorMessage', function (): void {
    $notification = makeNotification();

    $updated = $notification->withStatusChange(NotificationStatus::Failed, 'invalid recipient');

    expect($updated->errorMessage)->toBe('invalid recipient');
});

it('withProviderAttempt pins the provider without touching status', function (): void {
    $notification = makeNotification();

    $updated = $notification->withProviderAttempt('whatsapp-selfhosted', 'msg-123');

    expect($updated->providerCode)->toBe('whatsapp-selfhosted')
        ->and($updated->providerMessageId)->toBe('msg-123')
        ->and($updated->status)->toBe(NotificationStatus::Queued)
        ->and($notification->providerCode)->toBeNull('the original instance is untouched');
});
