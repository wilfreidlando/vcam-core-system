<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * Deliberately generic, mirroring `InvalidApiKey`: an unknown email and a
 * wrong password collapse into the same exception and the same 401, so a
 * caller cannot enumerate valid operator emails by observing which
 * failure they get back.
 */
final class InvalidOperatorCredentials extends RuntimeException
{
    public static function unknown(): self
    {
        return new self('No active operator matches the given credentials.');
    }
}
