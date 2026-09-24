<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\CreateChannelSessionUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionQrCodeUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionScreenshotUseCase;
use Core\Notification\Application\UseCase\ListChannelSessionsUseCase;
use Core\Notification\Application\UseCase\RequestChannelSessionPairingCodeUseCase;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\CreateChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Tests\Unit\Fakes\FakeChannelSessionProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationProviderRouter;
use Core\Notification\Tests\Unit\Fakes\FixedClock;
use Core\Notification\Tests\Unit\Fakes\InMemoryChannelSessionRepository;
use Core\Notification\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * @return array{0: FakeChannelSessionProvider, 1: FakeNotificationChannelProviderRegistry, 2: FakeNotificationProviderRouter, 3: InMemoryChannelSessionRepository}
 */
function sessionFixtures(): array
{
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);

    return [$provider, $registry, new FakeNotificationProviderRouter(['whatsapp-waha']), new InMemoryChannelSessionRepository];
}

function registerSession(InMemoryChannelSessionRepository $repository, ?string $projectId, string $ref): void
{
    $repository->save(new ChannelSession('id-'.$ref, $projectId, 'whatsapp', $ref, (new FixedClock)->now()));
}

function whatsapp(): NotificationChannel
{
    return NotificationChannel::fromString('whatsapp');
}

it('returns the qr code for a session registered for the project, without creating anything', function (): void {
    [$provider, $registry, $router, $sessions] = sessionFixtures();
    registerSession($sessions, 'project-1', 'shop-1');

    $qr = (new GetChannelSessionQrCodeUseCase($router, $registry, $sessions))
        ->get(new GetChannelSessionCommand('project-1', whatsapp(), 'shop-1'));

    expect($qr)->toBe($provider->nextQrCode)
        ->and($provider->startedSessions)->toBe([]);
});

it('refuses a qr code, screenshot or pairing code for a session that is not registered, and creates nothing', function (): void {
    [$provider, $registry, $router, $sessions] = sessionFixtures();
    $command = new GetChannelSessionCommand('project-1', whatsapp(), 'unknown');

    expect(fn () => (new GetChannelSessionQrCodeUseCase($router, $registry, $sessions))->get($command))->toThrow(ChannelSessionNotFound::class)
        ->and(fn () => (new GetChannelSessionScreenshotUseCase($router, $registry, $sessions))->get($command))->toThrow(ChannelSessionNotFound::class)
        ->and(fn () => (new RequestChannelSessionPairingCodeUseCase($router, $registry, $sessions))->request(
            new RequestChannelSessionPairingCodeCommand('project-1', whatsapp(), 'unknown', '237699009900'),
        ))->toThrow(ChannelSessionNotFound::class)
        ->and($provider->startedSessions)->toBe([]);
});

it('does not let a project reach another project\'s session, nor a global one, through the client endpoints', function (): void {
    [, $registry, $router, $sessions] = sessionFixtures();
    registerSession($sessions, 'project-B', 'shop-1');
    registerSession($sessions, null, 'shared');

    $useCase = new GetChannelSessionQrCodeUseCase($router, $registry, $sessions);

    expect(fn () => $useCase->get(new GetChannelSessionCommand('project-A', whatsapp(), 'shop-1')))->toThrow(ChannelSessionNotFound::class)
        ->and(fn () => $useCase->get(new GetChannelSessionCommand('project-A', whatsapp(), 'shared')))->toThrow(ChannelSessionNotFound::class);
});

it('returns a pairing code for a registered session', function (): void {
    [$provider, $registry, $router, $sessions] = sessionFixtures();
    $provider->nextPairingCode = 'WXYZ-1234';
    registerSession($sessions, 'project-1', 'shop-1');

    $view = (new RequestChannelSessionPairingCodeUseCase($router, $registry, $sessions))->request(
        new RequestChannelSessionPairingCodeCommand('project-1', whatsapp(), 'shop-1', '237699009900'),
    );

    expect($view->code)->toBe('WXYZ-1234');
});

it('creating a session starts it at the provider under the project-namespaced name and registers it', function (): void {
    [$provider, $registry, $router, $sessions] = sessionFixtures();

    $view = (new CreateChannelSessionUseCase($router, $registry, $sessions, new SequentialIdentifier, new FixedClock))
        ->create(new CreateChannelSessionCommand('project-1', whatsapp(), 'shop-1'));

    expect($provider->startedSessions)->toBe(['project-1_shop-1'])
        ->and($view->sessionRef)->toBe('shop-1')
        ->and($sessions->find('project-1', 'whatsapp', 'shop-1'))->not->toBeNull();
});

it('creating a global session starts it under its plain name and registers it without an owner', function (): void {
    [$provider, $registry, $router, $sessions] = sessionFixtures();

    (new CreateChannelSessionUseCase($router, $registry, $sessions, new SequentialIdentifier, new FixedClock))
        ->create(new CreateChannelSessionCommand(null, whatsapp(), 'shared'));

    expect($provider->startedSessions)->toBe(['shared'])
        ->and($sessions->find(null, 'whatsapp', 'shared'))->not->toBeNull();
});

it('creating the same session twice registers it once', function (): void {
    [, $registry, $router, $sessions] = sessionFixtures();
    $useCase = new CreateChannelSessionUseCase($router, $registry, $sessions, new SequentialIdentifier, new FixedClock);

    $useCase->create(new CreateChannelSessionCommand('project-1', whatsapp(), 'shop-1'));
    $useCase->create(new CreateChannelSessionCommand('project-1', whatsapp(), 'shop-1'));

    expect($sessions->sessions)->toHaveCount(1);
});

it('lists only the sessions of the requested owner', function (): void {
    [, , , $sessions] = sessionFixtures();
    registerSession($sessions, 'project-1', 'mine');
    registerSession($sessions, 'project-2', 'theirs');
    registerSession($sessions, null, 'shared');
    $useCase = new ListChannelSessionsUseCase($sessions);

    $mine = array_map(fn ($v) => $v->sessionRef, $useCase->list(new ListChannelSessionsCommand('project-1', whatsapp())));
    $global = array_map(fn ($v) => $v->sessionRef, $useCase->list(new ListChannelSessionsCommand(null, whatsapp())));

    expect($mine)->toBe(['mine'])->and($global)->toBe(['shared']);
});

it('throws when the primary provider for the channel has no session capability', function (): void {
    $provider = new FakeNotificationChannelProvider(ProviderCode::fromString('sms-only'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['sms-only']);

    expect(fn () => (new GetChannelSessionQrCodeUseCase($router, $registry, new InMemoryChannelSessionRepository))
        ->get(new GetChannelSessionCommand('project-1', NotificationChannel::fromString('sms'), 'anything')))
        ->toThrow(ChannelDoesNotSupportSessions::class);
});

it('scopes a session ref into a name a real WAHA accepts', function (): void {
    // WAHA rejects anything outside [A-Za-z0-9_-] with a 400 — the earlier
    // `<projectId>:<ref>` format made every QR/screenshot/pairing-code call
    // fail against a real instance while all fakes stayed green.
    expect(Core\Notification\Domain\Model\ScopedSessionRef::for('01a0d4a4-11d0-732b-b413-db21199feb62', 'main')->value)
        ->toMatch('/^[A-Za-z0-9_-]+$/');
});
