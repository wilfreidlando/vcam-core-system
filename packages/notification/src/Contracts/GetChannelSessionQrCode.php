<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface GetChannelSessionQrCode
{
    public function get(GetChannelSessionCommand $command): ChannelSessionQrCode;
}
