<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelSessionView;
use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Domain\Port\ChannelSessionRepository;

final readonly class ListChannelSessionsUseCase implements ListChannelSessions
{
    public function __construct(
        private ChannelSessionRepository $sessions,
    ) {}

    public function list(ListChannelSessionsCommand $command): array
    {
        $views = [];
        foreach ($this->sessions->findAllFor($command->projectId, $command->channel->value) as $session) {
            $views[] = new ChannelSessionView($session->sessionRef, $session->createdAt->format('Y-m-d H:i:s'));
        }

        return $views;
    }
}
