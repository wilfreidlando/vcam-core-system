<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * Deliberately generic: malformed token, unknown prefix, secret that fails
 * verification, and revoked key are collapsed into the same exception, and
 * the middleware turns all of them into the same 401. Distinguishing them in
 * the response would let a caller enumerate valid prefixes by observing which
 * failure they get back.
 */
final class InvalidApiKey extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('The bearer token is not a well-formed API key.');
    }

    public static function unknown(): self
    {
        return new self('No active API key matches the given credentials.');
    }

    public static function revoked(): self
    {
        return new self('No active API key matches the given credentials.');
    }
}
