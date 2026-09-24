<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionView;
use Core\Notification\Contracts\GetChannelSession;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Domain\Port\ChannelSessionRepository;

final readonly class GetChannelSessionUseCase implements GetChannelSession
{
    public function __construct(
        private ChannelSessionRepository $sessions,
    ) {}

    public function get(GetChannelSessionCommand $command): ChannelSessionView
    {
        $session = $this->sessions->find($command->projectId, $command->channel->value, $command->sessionRef)
            ?? throw new ChannelSessionNotFound($command->sessionRef);

        return new ChannelSessionView($session->sessionRef, $session->createdAt->format('Y-m-d H:i:s'));
    }
}
