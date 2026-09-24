<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionQrCode;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionQrCode;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Domain\Model\ScopedSessionRef;
use Core\Notification\Domain\Port\ChannelSessionRepository;

/**
 * Thin pass-through (ADR-0042 §4): resolves the channel's primary
 * provider and routes straight to it — no Core-side persistence, no
 * session tracking. The session must already exist (created by the
 * operator, never implicitly by this call).
 */
final readonly class GetChannelSessionQrCodeUseCase implements GetChannelSessionQrCode
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
    ) {}

    public function get(GetChannelSessionCommand $command): ChannelSessionQrCode
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

        return $gateway->getQrCode($sessionRef);
    }
}
