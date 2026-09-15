<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionPairingCodeView;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;

final readonly class RequestChannelSessionPairingCodeUseCase implements RequestChannelSessionPairingCode
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
    ) {}

    public function request(RequestChannelSessionPairingCodeCommand $command): ChannelSessionPairingCodeView
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId)[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $gateway->startSession($command->sessionRef);

        return new ChannelSessionPairingCodeView(
            $gateway->requestPairingCode($command->sessionRef, $command->phoneNumber),
        );
    }
}
