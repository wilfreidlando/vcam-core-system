<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface GetChannelSessionStatus
{
    /**
     * @throws ChannelSessionNotFound the session is not registered for this owner
     */
    public function get(GetChannelSessionCommand $command): ChannelSessionState;
}
