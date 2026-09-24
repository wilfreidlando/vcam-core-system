<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * A session registered for a Project: `sessionRef` is the name the client
 * puts in `variables.session` (no project prefix).
 */
final readonly class ChannelSessionView
{
    public function __construct(
        public string $sessionRef,
        public string $createdAt,
    ) {}
}
