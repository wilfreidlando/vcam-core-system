<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * The challenge token from `LoginChallengeCodec` is missing, malformed,
 * expired, or was issued for a different purpose than the one being
 * completed (e.g. an `mfa_enrollment` token presented to the `mfa/verify`
 * endpoint). Collapsed into one generic failure for the same reason
 * `InvalidApiKey`/`InvalidOperatorCredentials` are.
 */
final class MfaChallengeInvalid extends RuntimeException
{
    public static function make(): self
    {
        return new self('The login challenge is invalid or has expired.');
    }
}
