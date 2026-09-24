<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface GetChannelSession
{
    /**
     * @throws ChannelSessionNotFound
     */
    public function get(GetChannelSessionCommand $command): ChannelSessionView;
}
