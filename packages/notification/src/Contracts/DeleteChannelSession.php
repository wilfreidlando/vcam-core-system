<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * Operator-only (admin console). Removes the session at the provider (which
 * disconnects any linked phone) and unregisters it: clients can no longer
 * send with it.
 */
interface DeleteChannelSession
{
    /**
     * @throws ChannelDoesNotSupportSessions
     * @throws ChannelSessionNotFound
     */
    public function delete(DeleteChannelSessionCommand $command): void;
}
