<?php

declare(strict_types=1);

use Core\Notification\Application\UseCase\GetChannelSessionQrCodeUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionScreenshotUseCase;
use Core\Notification\Application\UseCase\RequestChannelSessionPairingCodeUseCase;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Notification\Tests\Unit\Fakes\FakeChannelSessionProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;

it('ensures the session is started before returning the qr code', function (): void {
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['whatsapp-waha']);

    $useCase = new GetChannelSessionQrCodeUseCase($router, $registry);

    $qr = $useCase->get(new GetChannelSessionCommand('project-1', NotificationChannel::fromString('whatsapp'), 'shop-1'));

    // Security review finding: sessionRef is namespaced by projectId
    // before it ever reaches the provider — never forwarded as the
    // caller supplied it.
    expect($provider->startedSessions)->toBe(['project-1_shop-1'])
        ->and($qr)->toBe($provider->nextQrCode);
});

it('scopes the same caller-supplied session ref differently per project', function (): void {
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['whatsapp-waha']);

    $useCase = new GetChannelSessionQrCodeUseCase($router, $registry);

    $useCase->get(new GetChannelSessionCommand('project-A', NotificationChannel::fromString('whatsapp'), 'shop-1'));
    $useCase->get(new GetChannelSessionCommand('project-B', NotificationChannel::fromString('whatsapp'), 'shop-1'));

    expect($provider->startedSessions)->toBe(['project-A_shop-1', 'project-B_shop-1']);
});

it('ensures the session is started before returning the screenshot', function (): void {
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['whatsapp-waha']);

    $useCase = new GetChannelSessionScreenshotUseCase($router, $registry);

    $screenshot = $useCase->get(new GetChannelSessionCommand('project-1', NotificationChannel::fromString('whatsapp'), 'shop-1'));

    expect($provider->startedSessions)->toBe(['project-1_shop-1'])
        ->and($screenshot)->toBe($provider->nextScreenshot);
});

it('requests a pairing code after ensuring the session is started', function (): void {
    $provider = new FakeChannelSessionProvider(ProviderCode::fromString('whatsapp-waha'));
    $provider->nextPairingCode = 'WXYZ-1234';
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['whatsapp-waha']);

    $useCase = new RequestChannelSessionPairingCodeUseCase($router, $registry);

    $view = $useCase->request(new RequestChannelSessionPairingCodeCommand(
        'project-1',
        NotificationChannel::fromString('whatsapp'),
        'shop-1',
        '237699009900',
    ));

    expect($provider->startedSessions)->toBe(['project-1_shop-1'])
        ->and($view->code)->toBe('WXYZ-1234');
});

it('throws when the primary provider for the channel has no session capability', function (): void {
    $provider = new FakeNotificationChannelProvider(ProviderCode::fromString('sms-only'));
    $registry = new FakeNotificationChannelProviderRegistry;
    $registry->register($provider);
    $router = new FakeNotificationProviderRouter(['sms-only']);

    $useCase = new GetChannelSessionQrCodeUseCase($router, $registry);

    expect(fn () => $useCase->get(new GetChannelSessionCommand('project-1', NotificationChannel::fromString('sms'), 'anything')))
        ->toThrow(ChannelDoesNotSupportSessions::class);
});

it('scopes a session ref into a name a real WAHA accepts', function (): void {
    // WAHA rejects anything outside [A-Za-z0-9_-] with a 400 — the earlier
    // `<projectId>:<ref>` format made every QR/screenshot/pairing-code call
    // fail against a real instance while all fakes stayed green.
    expect(Core\Notification\Domain\Model\ScopedSessionRef::for('01a0d4a4-11d0-732b-b413-db21199feb62', 'main')->value)
        ->toMatch('/^[A-Za-z0-9_-]+$/');
});
