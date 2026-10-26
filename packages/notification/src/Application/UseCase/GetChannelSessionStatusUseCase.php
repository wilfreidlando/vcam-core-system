<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\ChannelSessionAccess;
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
use Core\Support\Access\AccessScope;

final readonly class GetChannelSessionStatusUseCase implements GetChannelSessionStatus
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
    ) {}

    public function get(GetChannelSessionCommand $command, AccessScope $scope): ChannelSessionState
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId ?? '')[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        // ADR-0069 §2 — a session out of the caller's scope is "not
        // found", never "forbidden" (S-04). The check runs before the
        // provider is called, so an out-of-scope session never reaches
        // it.
        if (! ChannelSessionAccess::allows($command->projectId, $scope)) {
            throw new ChannelSessionNotFound($command->sessionRef);
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
