<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionView;
use Core\Notification\Contracts\CreateChannelSession;
use Core\Notification\Contracts\CreateChannelSessionCommand;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Model\ScopedSessionRef;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * The only place a session comes into being (called from the admin
 * console): created at the provider, then registered in the Core's own
 * table — which is what every send is later checked against. A provider
 * failure leaves nothing registered.
 */
final readonly class CreateChannelSessionUseCase implements CreateChannelSession
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function create(CreateChannelSessionCommand $command): ChannelSessionView
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId ?? '')[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $gateway->startSession(ScopedSessionRef::forOwner($command->projectId, $command->sessionRef)->value);

        $session = $this->sessions->find($command->projectId, $command->channel->value, $command->sessionRef)
            ?? new ChannelSession(
                $this->identifier->generate(),
                $command->projectId,
                $command->channel->value,
                $command->sessionRef,
                $this->clock->now(),
            );
        $this->sessions->save($session);

        return new ChannelSessionView($session->sessionRef, $session->createdAt->format('Y-m-d H:i:s'));
    }
}
