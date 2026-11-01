<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

interface ListChannelSessions
{
    /**
     * The scope is required (ADR-0069 §2): a listing never crosses the
     * projects the console account may see.
     *
     * @return list<ChannelSessionView>
     */
    public function list(ListChannelSessionsCommand $command, AccessScope $scope): array;
}
