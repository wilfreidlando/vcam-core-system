<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class ChannelSessionPairingCodeView
{
    public function __construct(
        public string $code,
    ) {}
}
