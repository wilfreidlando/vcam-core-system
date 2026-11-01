<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ChannelSessionView;
use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Support\Access\AccessScope;

final readonly class ListChannelSessionsUseCase implements ListChannelSessions
{
    public function __construct(
        private ChannelSessionRepository $sessions,
    ) {}

    public function list(ListChannelSessionsCommand $command, AccessScope $scope): array
    {
        // ADR-0069 §2 — a project outside the account's scope yields
        // nothing; the global sessions (no owner) belong to the platform.
        $projectIds = null;

        if ($command->projectId === null) {
            if (! $scope->allOrganizations) {
                return [];
            }

            // A global session has no owner (`project_id` NULL): the
            // scope's project list does not apply to it. Passing it
            // anyway would hide the platform's sessions from a scope that
            // sees every organization but not every project.
        } elseif (! $scope->allowsProject($command->projectId)) {
            return [];
        } else {
            // The scope filters at the query level too: another tenant's
            // sessions are never materialised.
            $projectIds = $scope->projectFilter();
        }

        $views = [];
        foreach ($this->sessions->findAllFor($command->projectId, $command->channel->value, $projectIds) as $session) {
            $views[] = new ChannelSessionView($session->sessionRef, $session->createdAt->format('Y-m-d H:i:s'));
        }

        return $views;
    }
}
