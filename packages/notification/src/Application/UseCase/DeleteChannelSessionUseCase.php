<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\DeleteChannelSession;
use Core\Notification\Contracts\DeleteChannelSessionCommand;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Domain\Model\ScopedSessionRef;
use Core\Notification\Domain\Port\ChannelSessionRepository;

/**
 * Provider first, registry second: if the provider refuses (auth, outage),
 * the registration stays — it must never disappear while the session still
 * lives at the provider, where it would be unmanageable from the console.
 */
final readonly class DeleteChannelSessionUseCase implements DeleteChannelSession
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
    ) {}

    public function delete(DeleteChannelSessionCommand $command): void
    {
        $session = $this->sessions->find($command->projectId, $command->channel->value, $command->sessionRef)
            ?? throw new ChannelSessionNotFound($command->sessionRef);

        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId ?? '')[0];
        $gateway = $this->registry->gatewayFor($providerCode);
        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $gateway->deleteSession(ScopedSessionRef::forOwner($command->projectId, $command->sessionRef)->value);
        $this->sessions->delete($session->projectId, $session->channel, $session->sessionRef);
    }
}
