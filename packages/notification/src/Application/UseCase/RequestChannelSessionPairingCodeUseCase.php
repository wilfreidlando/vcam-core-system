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
use Core\Notification\Domain\Model\ScopedSessionRef;

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

        $sessionRef = ScopedSessionRef::for($command->projectId, $command->sessionRef)->value;
        $gateway->startSession($sessionRef);

        return new ChannelSessionPairingCodeView(
            $gateway->requestPairingCode($sessionRef, $command->phoneNumber),
        );
    }
}
