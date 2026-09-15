<?php

declare(strict_types=1);

use Core\Notification\Contracts\NoProviderForNotificationChannelException;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Infrastructure\Routing\ConfigNotificationProviderRouter;

it('resolves the default ordered chain for a channel', function (): void {
    $router = new ConfigNotificationProviderRouter([
        'whatsapp' => ['whatsapp-selfhosted', 'whatsapp-cloud'],
    ]);

    $chain = $router->resolveProviders(NotificationChannel::fromString('whatsapp'), 'project-1');

    expect(array_map(fn ($c) => $c->toString(), $chain))->toBe(['whatsapp-selfhosted', 'whatsapp-cloud']);
});

it('a project override replaces the default chain entirely for that channel', function (): void {
    $router = new ConfigNotificationProviderRouter(
        defaults: ['whatsapp' => ['whatsapp-selfhosted']],
        projectOverrides: ['project-1' => ['whatsapp' => ['whatsapp-cloud']]],
    );

    $chain = $router->resolveProviders(NotificationChannel::fromString('whatsapp'), 'project-1');

    expect(array_map(fn ($c) => $c->toString(), $chain))->toBe(['whatsapp-cloud']);
});

it('rejects an unrouted channel', function (): void {
    $router = new ConfigNotificationProviderRouter([]);

    $router->resolveProviders(NotificationChannel::fromString('sms'), 'project-1');
})->throws(NoProviderForNotificationChannelException::class);
