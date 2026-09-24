<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Model\ChannelSession;

interface ChannelSessionRepository
{
    /**
     * Idempotent on `(projectId, channel, sessionRef)`.
     */
    public function save(ChannelSession $session): void;

    /**
     * Exact owner match: `$projectId === null` means the global one.
     */
    public function find(?string $projectId, string $channel, string $sessionRef): ?ChannelSession;

    /**
     * What a send from `$projectId` may use for this name: the Project's own
     * registration first, else a global one.
     */
    public function findUsableFor(string $projectId, string $channel, string $sessionRef): ?ChannelSession;

    /**
     * @return list<ChannelSession> oldest first; `$projectId === null` lists the global ones
     */
    public function findAllFor(?string $projectId, string $channel): array;
}
