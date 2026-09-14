<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Credential-level environment. Sandbox and live data, credentials and effects are
 * fully isolated: no real money ever moves in sandbox.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';

    public function isLive(): bool
    {
        return $this === self::Live;
    }
}
