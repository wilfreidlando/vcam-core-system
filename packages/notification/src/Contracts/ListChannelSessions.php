<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface ListChannelSessions
{
    /**
     * Only the sessions registered for the calling Project.
     *
     * @return list<ChannelSessionView>
     */
    public function list(ListChannelSessionsCommand $command): array;
}
