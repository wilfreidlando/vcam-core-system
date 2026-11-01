<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Access\AccessScope;

interface GetChannelSessionScreenshot
{
    /**
     * The scope is required (ADR-0069 §2): a session outside the
     * caller's scope is "not found", never "forbidden" (S-04).
     */
    public function get(GetChannelSessionCommand $command, AccessScope $scope): ChannelSessionScreenshot;
}
