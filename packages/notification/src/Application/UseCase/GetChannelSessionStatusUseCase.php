<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionState;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionStatus;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Domain\Model\ScopedSessionRef;
use Core\Notification\Domain\Port\ChannelSessionRepository;

final readonly class GetChannelSessionStatusUseCase implements GetChannelSessionStatus
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
    ) {}

    public function get(GetChannelSessionCommand $command): ChannelSessionState
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

        return $gateway->getSessionState($sessionRef);
    }
}
