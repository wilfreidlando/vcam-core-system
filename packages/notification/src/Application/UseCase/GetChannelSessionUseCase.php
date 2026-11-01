<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\ChannelSessionAccess;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionView;
use Core\Notification\Contracts\GetChannelSession;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Support\Access\AccessScope;

final readonly class GetChannelSessionUseCase implements GetChannelSession
{
    public function __construct(
        private ChannelSessionRepository $sessions,
    ) {}

    public function get(GetChannelSessionCommand $command, AccessScope $scope): ChannelSessionView
    {
        // ADR-0069 §2 — a session out of the caller's scope is "not
        // found", never "forbidden" (S-04): the answer must not confirm
        // that the resource exists.
        if (! ChannelSessionAccess::allows($command->projectId, $scope)) {
            throw new ChannelSessionNotFound($command->sessionRef);
        }

        $session = $this->sessions->find($command->projectId, $command->channel->value, $command->sessionRef)
            ?? throw new ChannelSessionNotFound($command->sessionRef);

        return new ChannelSessionView($session->sessionRef, $session->createdAt->format('Y-m-d H:i:s'));
    }
}
