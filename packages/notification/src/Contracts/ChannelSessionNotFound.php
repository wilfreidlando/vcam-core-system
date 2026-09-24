<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * The session a request names is not registered for the calling Project (`notification_channel_sessions`).
 * Sessions are created by the platform operator in the admin console,
 * never by an integrating client — so this is raised instead of sending
 * (or silently creating one).
 */
final class ChannelSessionNotFound extends RuntimeException
{
    public function __construct(public readonly string $sessionRef)
    {
        parent::__construct(sprintf(
            'The WhatsApp session "%s" is not registered for this project. Sessions are created by the platform administrator: ask for it to be created and linked, then retry. Nothing was sent.',
            $sessionRef,
        ));
    }
}
