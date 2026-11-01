<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\GetNotificationDetailsUseCase;
use Core\Notification\Contracts\GetNotificationDetailsCommand;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationDeliveryRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Support\Access\AccessScope;

function detailableNotification(string $id, string $projectId): Notification
{
    return new Notification(
        id: $id,
        projectId: $projectId,
        channel: 'whatsapp',
        toPartyRef: 'party-1',
        contentType: 'text',
        idempotencyKey: 'idem-'.$id,
        status: NotificationStatus::Failed,
        providerCode: 'whatsapp-waha',
        providerMessageId: null,
        errorMessage: 'Session not linked',
        content: ['body' => 'Bonjour'],
        variables: ['phone_number' => '237699009900'],
    );
}

function detailsUseCase(InMemoryNotificationRepository $notifications): GetNotificationDetailsUseCase
{
    return new GetNotificationDetailsUseCase($notifications, new InMemoryNotificationDeliveryRepository);
}

it('returns the detail of a notification of the asked project', function (): void {
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(detailableNotification('n1', 'project-a'));

    $view = detailsUseCase($notifications)->get(
        new GetNotificationDetailsCommand('n1', 'project-a'),
        AccessScope::platform(),
    );

    assert($view !== null);

    expect($view->id)->toBe('n1')
        ->and($view->projectId)->toBe('project-a')
        ->and($view->errorMessage)->toBe('Session not linked')
        ->and($view->content)->toBe(['body' => 'Bonjour']);
});

it('returns nothing for a notification of another project', function (): void {
    // ADR-0069 §2 — the filter that used to sit in the console
    // controller now lives in the port.
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(detailableNotification('n1', 'project-b'));

    $view = detailsUseCase($notifications)->get(
        new GetNotificationDetailsCommand('n1', 'project-a'),
        AccessScope::platform(),
    );

    expect($view)->toBeNull();
});

it('returns nothing for a notification outside the account\'s scope', function (): void {
    $notifications = new InMemoryNotificationRepository;
    $notifications->create(detailableNotification('n1', 'project-b'));

    $view = detailsUseCase($notifications)->get(
        new GetNotificationDetailsCommand('n1', 'project-b'),
        AccessScope::forProject('project-a'),
    );

    expect($view)->toBeNull();
});

it('returns nothing for an unknown notification', function (): void {
    $view = detailsUseCase(new InMemoryNotificationRepository)->get(
        new GetNotificationDetailsCommand('unknown', 'project-a'),
        AccessScope::platform(),
    );

    expect($view)->toBeNull();
});
