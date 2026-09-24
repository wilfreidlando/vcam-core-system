<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionPairingCodeView;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Notification\Domain\Model\ScopedSessionRef;
use Core\Notification\Domain\Port\ChannelSessionRepository;

final readonly class RequestChannelSessionPairingCodeUseCase implements RequestChannelSessionPairingCode
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
    ) {}

    public function request(RequestChannelSessionPairingCodeCommand $command): ChannelSessionPairingCodeView
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId ?? '')[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $sessionRef = ScopedSessionRef::forOwner($command->projectId, $command->sessionRef)->value;
        // Creation is the operator's job (CreateChannelSession); a client
        // asking about a session that is not registered for its Project gets
        // an explicit error, never an implicitly created one.
        if (! ($this->sessions->find($command->projectId, $command->channel->value, $command->sessionRef) !== null)) {
            throw new ChannelSessionNotFound($command->sessionRef);
        }

        return new ChannelSessionPairingCodeView(
            $gateway->requestPairingCode($sessionRef, $command->phoneNumber),
        );
    }
}
