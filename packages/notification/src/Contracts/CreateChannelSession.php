<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * Operator-only (admin console): the ONLY way a session comes into being.
 * Idempotent — creating one that already exists just returns its state.
 */
interface CreateChannelSession
{
    /**
     * @throws ChannelDoesNotSupportSessions
     */
    public function create(CreateChannelSessionCommand $command): ChannelSessionView;
}
