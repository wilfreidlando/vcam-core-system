<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\SendNotificationUseCase;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\ProviderDispatchResult;
use Core\Notification\Contracts\SendNotificationCommand;
use Core\Notification\Domain\Exception\NotificationTemplateNotFound;
use Core\Notification\Domain\Model\NotificationTemplate;
use Core\Notification\NotificationStatus;
use Core\Notification\NotificationTemplateKind;
use Core\Notification\NotificationTemplateStatus;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationProviderRouter;
use Core\Notification\Tests\Unit\Fakes\FakePartyFinder;
use Core\Notification\Tests\Unit\Fakes\FixedClock;
use Core\Notification\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationDeliveryRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationTemplateRepository;
use Core\Notification\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Notification\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Integration\Provider\ProviderCode;
use Psr\Log\NullLogger;

/**
 * @param list<string> $providerCodes ordered primary-first
 * @return array{
 *     0: SendNotificationUseCase,
 *     1: InMemoryNotificationRepository,
 *     2: InMemoryNotificationTemplateRepository,
 *     3: InMemoryNotificationDeliveryRepository,
 *     4: FakeNotificationChannelProviderRegistry,
 *     5: InMemoryEventOutbox,
 *     6: FakePartyFinder,
 * }
 */
function makeSendUseCase(array $providerCodes = ['fake-provider']): array
{
    $notifications = new InMemoryNotificationRepository;
    $templates = new InMemoryNotificationTemplateRepository;
    $deliveries = new InMemoryNotificationDeliveryRepository;
    $router = new FakeNotificationProviderRouter($providerCodes);
    $registry = new FakeNotificationChannelProviderRegistry;
    $partyFinder = new FakePartyFinder;
    $outbox = new InMemoryEventOutbox;

    $useCase = new SendNotificationUseCase(
        $notifications,
        $templates,
        $deliveries,
        $router,
        $registry,
        $partyFinder,
        new PassthroughUnitOfWork,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
    );

    return [$useCase, $notifications, $templates, $deliveries, $registry, $outbox, $partyFinder];
}

function approvedTemplate(string $key = 'booking_confirmed', string $channel = 'whatsapp', int $version = 1): NotificationTemplate
{
    return new NotificationTemplate(
        id: 'tpl-'.$version,
        projectId: 'project-1',
        key: $key,
        channel: $channel,
        kind: NotificationTemplateKind::Session,
        status: NotificationTemplateStatus::Approved,
        version: $version,
        body: ['text' => 'Hello {{name}}'],
    );
}

function sendCommand(string $idempotencyKey = 'idem-1'): SendNotificationCommand
{
    return new SendNotificationCommand(
        projectId: 'project-1',
        channel: NotificationChannel::fromString('whatsapp'),
        toPartyRef: 'user-1',
        template: 'booking_confirmed',
        variables: ['name' => 'Awa'],
        idempotencyKey: $idempotencyKey,
    );
}

it('resolves the recipient as a correlation Party, never a contact address', function (): void {
    [$useCase, , $templates, , $registry, , $partyFinder] = makeSendUseCase();
    $templates->add(approvedTemplate());
    $registry->register(new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider')));

    $useCase->send(sendCommand());

    expect($partyFinder->calls)->toHaveCount(1)
        ->and($partyFinder->calls[0]->externalRef)->toBe('user-1')
        ->and($partyFinder->calls[0]->type)->toBe('notification_recipient');
});

it('sends via the primary provider and records the delivery attempt', function (): void {
    [$useCase, $notifications, $templates, $deliveries, $registry] = makeSendUseCase(['whatsapp-selfhosted']);
    $templates->add(approvedTemplate());
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('whatsapp-selfhosted'));
    $registry->register($gateway);

    $view = $useCase->send(sendCommand());

    expect($view->status)->toBe(NotificationStatus::Sent)
        ->and($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0]->recipientRef)->toBe('user-1')
        ->and($gateway->sent[0]->variables)->toBe(['name' => 'Awa'])
        ->and($deliveries->records)->toHaveCount(1)
        ->and($deliveries->records[0]['providerCode'])->toBe('whatsapp-selfhosted')
        ->and($deliveries->records[0]['attempt'])->toBe(1)
        ->and($notifications->count())->toBe(1);
});

it('replays the same notification for a repeated idempotency key, never sending twice', function (): void {
    [$useCase, , $templates, , $registry] = makeSendUseCase();
    $templates->add(approvedTemplate());
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider'));
    $registry->register($gateway);

    $first = $useCase->send(sendCommand());
    $second = $useCase->send(sendCommand());

    expect($second->id)->toBe($first->id)
        ->and($gateway->sent)->toHaveCount(1);
});

it('falls back to the next provider when the primary throws', function (): void {
    [$useCase, , $templates, $deliveries, $registry] = makeSendUseCase(['primary', 'secondary']);
    $templates->add(approvedTemplate());
    $primary = new FakeNotificationChannelProvider(ProviderCode::fromString('primary'));
    $primary->onSend = function (): never {
        throw new RuntimeException('primary is down');
    };
    $secondary = new FakeNotificationChannelProvider(ProviderCode::fromString('secondary'));
    $registry->register($primary);
    $registry->register($secondary);

    $view = $useCase->send(sendCommand());

    expect($view->status)->toBe(NotificationStatus::Sent)
        ->and($primary->sent)->toHaveCount(1)
        ->and($secondary->sent)->toHaveCount(1)
        ->and($deliveries->records)->toHaveCount(2)
        ->and($deliveries->records[0]['providerCode'])->toBe('primary')
        ->and($deliveries->records[0]['status'])->toBe(NotificationStatus::Failed)
        ->and($deliveries->records[1]['providerCode'])->toBe('secondary')
        ->and($deliveries->records[1]['attempt'])->toBe(2);
});

it('marks the notification failed and publishes notification.failed when every provider fails', function (): void {
    [$useCase, $notifications, $templates, $deliveries, $registry, $outbox] = makeSendUseCase(['primary', 'secondary']);
    $templates->add(approvedTemplate());
    foreach (['primary', 'secondary'] as $code) {
        $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString($code));
        $gateway->onSend = function (): never {
            throw new RuntimeException('down');
        };
        $registry->register($gateway);
    }

    $view = $useCase->send(sendCommand());

    expect($view->status)->toBe(NotificationStatus::Failed)
        ->and($deliveries->records)->toHaveCount(2)
        ->and($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('notification.failed')
        ->and($notifications->count())->toBe(1);
});

it('never publishes an outbox event on a successful send (queued/sent are not canonical events)', function (): void {
    [$useCase, , $templates, , $registry, $outbox] = makeSendUseCase();
    $templates->add(approvedTemplate());
    $registry->register(new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider')));

    $useCase->send(sendCommand());

    expect($outbox->events)->toHaveCount(0);
});

it('rejects sending against a template that does not exist', function (): void {
    [$useCase] = makeSendUseCase();

    $useCase->send(sendCommand());
})->throws(NotificationTemplateNotFound::class);

it('rejects sending against a template that is only pending approval', function (): void {
    [$useCase, , $templates, , $registry] = makeSendUseCase();
    $templates->add(new NotificationTemplate(
        id: 'tpl-pending',
        projectId: 'project-1',
        key: 'booking_confirmed',
        channel: 'whatsapp',
        kind: NotificationTemplateKind::Template,
        status: NotificationTemplateStatus::Pending,
        version: 1,
        body: [],
    ));
    $registry->register(new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider')));

    $useCase->send(sendCommand());
})->throws(NotificationTemplateNotFound::class);

it('picks the highest version of an approved template', function (): void {
    [$useCase, , $templates, , $registry] = makeSendUseCase();
    $templates->add(approvedTemplate(version: 1));
    $templates->add(approvedTemplate(version: 3));
    $templates->add(approvedTemplate(version: 2));
    $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('fake-provider'));
    $gateway->nextResult = new ProviderDispatchResult('msg-1', NotificationStatus::Sent);
    $registry->register($gateway);

    $useCase->send(sendCommand());

    expect($gateway->sent[0]->templateVersion)->toBe(3);
});
