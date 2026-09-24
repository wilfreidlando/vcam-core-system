<?php

declare(strict_types=1);

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Application\UseCase\SendFileNotificationUseCase;
use Core\Notification\Application\UseCase\SendImageNotificationUseCase;
use Core\Notification\Application\UseCase\SendTextNotificationUseCase;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendFileNotificationCommand;
use Core\Notification\Contracts\SendImageNotificationCommand;
use Core\Notification\Contracts\SendTextNotificationCommand;
use Core\Notification\NotificationStatus;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationProviderRouter;
use Core\Notification\Tests\Unit\Fakes\FakePartyFinder;
use Core\Notification\Tests\Unit\Fakes\FixedClock;
use Core\Notification\Tests\Unit\Fakes\InMemoryChannelSessionRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationDeliveryRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Notification\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Notification\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Integration\Provider\ProviderCode;
use Psr\Log\NullLogger;

/**
 * @param list<string> $providerCodes ordered primary-first
 * @return array{
 *     0: NotificationSendCascade,
 *     1: InMemoryNotificationRepository,
 *     2: InMemoryNotificationDeliveryRepository,
 *     3: FakeNotificationChannelProviderRegistry,
 *     4: InMemoryEventOutbox,
 *     5: FakePartyFinder,
 * }
 */
function makeCascade(array $providerCodes = ['fake-provider']): array
{
    $notifications = new InMemoryNotificationRepository;
    $deliveries = new InMemoryNotificationDeliveryRepository;
    $router = new FakeNotificationProviderRouter($providerCodes);
    $registry = new FakeNotificationChannelProviderRegistry;
    $partyFinder = new FakePartyFinder;
    $outbox = new InMemoryEventOutbox;

    $cascade = new NotificationSendCascade(
        $notifications,
        $deliveries,
        $router,
        $registry,
        new InMemoryChannelSessionRepository,
        $partyFinder,
        new PassthroughUnitOfWork,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
    );

    return [$cascade, $notifications, $deliveries, $registry, $outbox, $partyFinder];
}

function textCommand(string $idempotencyKey = 'idem-1'): SendTextNotificationCommand
{
    return new SendTextNotificationCommand(
        projectId: 'project-1',
        channel: NotificationChannel::fromString('whatsapp'),
        toPartyRef: 'user-1',
        text: 'Hello {{name}}',
        variables: ['name' => 'Awa'],
        idempotencyKey: $idempotencyKey,
    );
}

it('resolves the recipient as a correlation Party, never a contact address', function (): void {
    [$cascade, , , $registry, , $partyFinder] = makeCascade();
    $registry->register(new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider')));

    (new SendTextNotificationUseCase($cascade))->send(textCommand());

    expect($partyFinder->calls)->toHaveCount(1)
        ->and($partyFinder->calls[0]->externalRef)->toBe('user-1')
        ->and($partyFinder->calls[0]->type)->toBe('notification_recipient');
});

it('sends via the primary provider with a text body and records the delivery attempt', function (): void {
    [$cascade, $notifications, $deliveries, $registry] = makeCascade(['whatsapp-waha']);
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry->register($gateway);

    $view = (new SendTextNotificationUseCase($cascade))->send(textCommand());

    expect($view->status)->toBe(NotificationStatus::Sent->value)
        ->and($view->contentType)->toBe('text')
        ->and($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0]->recipientRef)->toBe('user-1')
        ->and($gateway->sent[0]->variables)->toBe(['name' => 'Awa'])
        ->and($gateway->sent[0]->body)->toBe(['type' => 'text', 'text' => 'Hello {{name}}'])
        ->and($deliveries->records)->toHaveCount(1)
        ->and($deliveries->records[0]['providerCode'])->toBe('whatsapp-waha')
        ->and($deliveries->records[0]['attempt'])->toBe(1)
        ->and($notifications->count())->toBe(1);
});

it('builds an image body from SendImageNotificationCommand', function (): void {
    [$cascade, , , $registry] = makeCascade(['whatsapp-waha']);
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry->register($gateway);

    $command = new SendImageNotificationCommand(
        projectId: 'project-1',
        channel: NotificationChannel::fromString('whatsapp'),
        toPartyRef: 'user-1',
        mediaUrl: 'https://files.test/r.jpg',
        caption: 'Receipt',
        filename: 'receipt.jpg',
        mimetype: 'image/jpeg',
        variables: [],
        idempotencyKey: 'idem-image',
    );

    $view = (new SendImageNotificationUseCase($cascade))->send($command);

    expect($view->contentType)->toBe('image')
        ->and($gateway->sent[0]->body)->toBe([
            'type' => 'image',
            'media_url' => 'https://files.test/r.jpg',
            'caption' => 'Receipt',
            'filename' => 'receipt.jpg',
            'mimetype' => 'image/jpeg',
        ]);
});

it('builds a file body from SendFileNotificationCommand', function (): void {
    [$cascade, , , $registry] = makeCascade(['whatsapp-waha']);
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry->register($gateway);

    $command = new SendFileNotificationCommand(
        projectId: 'project-1',
        channel: NotificationChannel::fromString('whatsapp'),
        toPartyRef: 'user-1',
        mediaUrl: 'https://files.test/invoice.pdf',
        filename: 'invoice.pdf',
        mimetype: 'application/pdf',
        caption: null,
        variables: [],
        idempotencyKey: 'idem-file',
    );

    $view = (new SendFileNotificationUseCase($cascade))->send($command);

    expect($view->contentType)->toBe('file')
        ->and($gateway->sent[0]->body)->toBe([
            'type' => 'file',
            'media_url' => 'https://files.test/invoice.pdf',
            'filename' => 'invoice.pdf',
            'mimetype' => 'application/pdf',
            'caption' => null,
        ]);
});

it('replays the same notification for a repeated idempotency key, never sending twice', function (): void {
    [$cascade, , , $registry] = makeCascade();
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider'));
    $registry->register($gateway);
    $useCase = new SendTextNotificationUseCase($cascade);

    $first = $useCase->send(textCommand());
    $second = $useCase->send(textCommand());

    expect($second->id)->toBe($first->id)
        ->and($gateway->sent)->toHaveCount(1);
});

it('falls back to the next provider when the primary throws', function (): void {
    [$cascade, , $deliveries, $registry] = makeCascade(['primary', 'secondary']);
    $primary = new FakeNotificationChannelProvider(ProviderCode::fromString('primary'));
    $primary->onSend = function (): never {
        throw new RuntimeException('primary is down');
    };
    $secondary = new FakeNotificationChannelProvider(ProviderCode::fromString('secondary'));
    $registry->register($primary);
    $registry->register($secondary);

    $view = (new SendTextNotificationUseCase($cascade))->send(textCommand());

    expect($view->status)->toBe(NotificationStatus::Sent->value)
        ->and($primary->sent)->toHaveCount(1)
        ->and($secondary->sent)->toHaveCount(1)
        ->and($deliveries->records)->toHaveCount(2)
        ->and($deliveries->records[0]['providerCode'])->toBe('primary')
        ->and($deliveries->records[0]['status'])->toBe(NotificationStatus::Failed)
        ->and($deliveries->records[1]['providerCode'])->toBe('secondary')
        ->and($deliveries->records[1]['attempt'])->toBe(2);
});

it('marks the notification failed and publishes notification.failed when every provider fails', function (): void {
    [$cascade, $notifications, $deliveries, $registry, $outbox] = makeCascade(['primary', 'secondary']);
    foreach (['primary', 'secondary'] as $code) {
        $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString($code));
        $gateway->onSend = function (): never {
            throw new RuntimeException('down');
        };
        $registry->register($gateway);
    }

    $view = (new SendTextNotificationUseCase($cascade))->send(textCommand());

    expect($view->status)->toBe(NotificationStatus::Failed->value)
        ->and($deliveries->records)->toHaveCount(2)
        ->and($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('notification.failed')
        // Same payload shape as HandleNotificationStatusChangedUseCase's
        // own `notification.failed` (a provider callback reporting failure
        // later) — a webhook receiver must see one consistent shape for
        // this event type regardless of which path produced it (review
        // finding: this producer used to omit `status` entirely).
        ->and($outbox->events[0]->payload)->toHaveKey('status', 'failed')
        ->and($notifications->count())->toBe(1);
});

it('never publishes an outbox event on a successful send (queued/sent are not canonical events)', function (): void {
    [$cascade, , , $registry, $outbox] = makeCascade();
    $registry->register(new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider')));

    (new SendTextNotificationUseCase($cascade))->send(textCommand());

    expect($outbox->events)->toHaveCount(0);
});
