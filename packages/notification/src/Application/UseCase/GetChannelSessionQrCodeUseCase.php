<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionQrCode;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionQrCode;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Domain\Model\ScopedSessionRef;

/**
 * Thin pass-through (ADR-0042 §4): resolves the channel's primary
 * provider and routes straight to it — no Core-side persistence, no
 * session tracking. `startSession` is called first (idempotent on the
 * provider side) so a caller never has to sequence a separate "create"
 * step before asking for a QR code.
 */
final readonly class GetChannelSessionQrCodeUseCase implements GetChannelSessionQrCode
{
    public function __construct(
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
    ) {}

    public function get(GetChannelSessionCommand $command): ChannelSessionQrCode
    {
        $providerCode = $this->router->resolveProviders($command->channel, $command->projectId)[0];
        $gateway = $this->registry->gatewayFor($providerCode);

        if (! $gateway instanceof ChannelSessionProvider) {
            throw new ChannelDoesNotSupportSessions($providerCode, $gateway::class);
        }

        $sessionRef = ScopedSessionRef::for($command->projectId, $command->sessionRef)->value;
        $gateway->startSession($sessionRef);

        return $gateway->getQrCode($sessionRef);
    }
}
