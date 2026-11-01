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

    public function delete(?string $projectId, string $channel, string $sessionRef): void;

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
     * @param list<string>|null $projectIds when given, only the sessions
     *                                      owned by those projects are
     *                                      returned (ADR-0069 §2) — the
     *                                      scope filters at the query level
     * @return list<ChannelSession> oldest first; `$projectId === null` lists the global ones
     */
    public function findAllFor(?string $projectId, string $channel, ?array $projectIds = null): array;
}
