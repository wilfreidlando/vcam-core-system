<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionScreenshot;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionScreenshot;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;

final readonly class GetChannelSessionScreenshotUseCase implements GetChannelSessionScreenshot
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
    ) {}

    public function get(GetChannelSessionCommand $command): ChannelSessionScreenshot
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId)[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $gateway->startSession($command->sessionRef);

        return $gateway->getScreenshot($command->sessionRef);
    }
}
