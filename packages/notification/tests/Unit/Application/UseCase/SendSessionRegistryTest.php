<?php

declare(strict_types=1);

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Application\UseCase\SendTextNotificationUseCase;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\MissingChannelSession;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendTextNotificationCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Tests\Unit\Fakes\FakeChannelSessionProvider;
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
 * @return array{0: SendTextNotificationUseCase, 1: InMemoryChannelSessionRepository, 2: InMemoryNotificationRepository, 3: FakeChannelSessionProvider, 4: FakePartyFinder}
 */
function sessionSendFixtures(): array
{
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $sessions = new InMemoryChannelSessionRepository;
    $notifications = new InMemoryNotificationRepository;
    $finder = new FakePartyFinder;

    $cascade = new NotificationSendCascade(
        $notifications,
        new InMemoryNotificationDeliveryRepository,
        new FakeNotificationProviderRouter(['whatsapp-waha']),
        $registry,
        $sessions,
        $finder,
        new PassthroughUnitOfWork,
        new InMemoryEventOutbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
    );

    return [new SendTextNotificationUseCase($cascade), $sessions, $notifications, $provider, $finder];
}

function sessionSend(?string $session): SendTextNotificationCommand
{
    return new SendTextNotificationCommand(
        projectId: 'project-1',
        channel: NotificationChannel::fromString('whatsapp'),
        toPartyRef: 'user-1',
        text: 'Hello',
        variables: ['phone_number' => '+237699009900'] + ($session === null ? [] : ['session' => $session]),
        idempotencyKey: 'idem-1',
    );
}

it('refuses a send naming a session that is not registered, and writes and sends nothing', function (): void {
    [$useCase, , $notifications, , $finder] = sessionSendFixtures();

    expect(fn () => $useCase->send(sessionSend('ghost')))->toThrow(ChannelSessionNotFound::class)
        ->and($notifications->count())->toBe(0)
        ->and($finder->calls)->toBe([]);
});

it('refuses a send without any session', function (): void {
    [$useCase] = sessionSendFixtures();

    expect(fn () => $useCase->send(sessionSend(null)))->toThrow(MissingChannelSession::class);
});

it('refuses a session registered for another project', function (): void {
    [$useCase, $sessions] = sessionSendFixtures();
    $sessions->save(new ChannelSession('s1', 'project-2', 'whatsapp', 'shop', (new FixedClock)->now()));

    expect(fn () => $useCase->send(sessionSend('shop')))->toThrow(ChannelSessionNotFound::class);
});

it('sends from the project\'s own session, handing the provider the project-namespaced name', function (): void {
    [$useCase, $sessions, , $provider] = sessionSendFixtures();
    $sessions->save(new ChannelSession('s1', 'project-1', 'whatsapp', 'shop', (new FixedClock)->now()));

    $view = $useCase->send(sessionSend('shop'));

    expect($view->status)->toBe('sent')->and($provider->lastSentSession)->toBe('project-1_shop');
});

it('sends from a global session under its plain name', function (): void {
    [$useCase, $sessions, , $provider] = sessionSendFixtures();
    $sessions->save(new ChannelSession('s1', null, 'whatsapp', 'shared', (new FixedClock)->now()));

    $view = $useCase->send(sessionSend('shared'));

    expect($view->status)->toBe('sent')->and($provider->lastSentSession)->toBe('shared');
});

it('prefers the project\'s own session over a global one with the same name', function (): void {
    [$useCase, $sessions, , $provider] = sessionSendFixtures();
    $sessions->save(new ChannelSession('g', null, 'whatsapp', 'shop', (new FixedClock)->now()));
    $sessions->save(new ChannelSession('p', 'project-1', 'whatsapp', 'shop', (new FixedClock)->now()));

    $useCase->send(sessionSend('shop'));

    expect($provider->lastSentSession)->toBe('project-1_shop');
});
