<?php

declare(strict_types=1);

use Core\Notification\Domain\Model\NotificationTemplate;
use Core\Notification\NotificationTemplateKind;
use Core\Notification\NotificationTemplateStatus;

it('is sendable only when approved', function (): void {
    $approved = new NotificationTemplate(
        id: 'tpl-1',
        projectId: 'project-1',
        key: 'booking_confirmed',
        channel: 'whatsapp',
        kind: NotificationTemplateKind::Template,
        status: NotificationTemplateStatus::Approved,
        version: 1,
        body: ['text' => 'Hello {{name}}'],
    );
    $pending = new NotificationTemplate(
        id: 'tpl-2',
        projectId: 'project-1',
        key: 'booking_confirmed',
        channel: 'whatsapp',
        kind: NotificationTemplateKind::Template,
        status: NotificationTemplateStatus::Pending,
        version: 2,
        body: ['text' => 'Hello {{name}}, updated'],
    );

    expect($approved->isSendable())->toBeTrue()
        ->and($pending->isSendable())->toBeFalse();
});
