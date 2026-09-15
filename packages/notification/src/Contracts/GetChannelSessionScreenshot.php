<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface GetChannelSessionScreenshot
{
    public function get(GetChannelSessionCommand $command): ChannelSessionScreenshot;
}
