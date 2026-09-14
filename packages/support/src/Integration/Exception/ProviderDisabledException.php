<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

use Core\Support\Integration\Provider\ProviderCode;

/**
 * Raised when the provider is administratively disabled (`enabled = false`
 * in `ProviderConfig`). The call is refused before any HTTP traffic is
 * issued — useful as a kill switch during incidents or maintenance.
 *
 * `disabled` is the only configuration-driven refusal; transient and
 * permanent failures keep their own exception types so the caller can tell
 * "we deliberately cut this provider" from "this provider is broken".
 */
final class ProviderDisabledException extends ProviderException
{
    public static function forCode(ProviderCode $code): self
    {
        return new self(
            sprintf('Provider "%s" is administratively disabled.', $code->toString()),
            $code,
        );
    }
}
