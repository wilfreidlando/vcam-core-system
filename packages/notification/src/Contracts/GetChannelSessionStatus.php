<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

interface GetChannelSessionStatus
{
    /**
     * The scope is required (ADR-0069 §2): a session outside the
     * caller's scope is "not found", never "forbidden" (S-04).
     *
     * @throws ChannelSessionNotFound the session is not registered for this owner
     */
    public function get(GetChannelSessionCommand $command, AccessScope $scope): ChannelSessionState;
}
