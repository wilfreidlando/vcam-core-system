<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * A session-based channel (WhatsApp) sends from the caller's OWN linked
 * session, named in `variables['session']` — there is deliberately no
 * shared platform-wide fallback: a message must never leave from a number
 * the calling Project did not link itself. The HTTP layer already rejects
 * a request without it (422); this guards any path that reaches the
 * adapter some other way.
 */
final class MissingChannelSession extends RuntimeException
{
    public static function forRequest(): self
    {
        return new self('A WhatsApp send must name the caller\'s own linked session in variables[\'session\']. Nothing was sent.');
    }

    public static function forMessage(string $appMessageRef): self
    {
        return new self(sprintf(
            'OutboundMessage "%s" is missing variables[\'session\'] — the caller\'s own linked session is required to send.',
            $appMessageRef,
        ));
    }
}
