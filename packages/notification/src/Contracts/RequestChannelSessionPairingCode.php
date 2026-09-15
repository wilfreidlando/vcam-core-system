<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

interface RequestChannelSessionPairingCode
{
    public function request(RequestChannelSessionPairingCodeCommand $command): ChannelSessionPairingCodeView;
}
