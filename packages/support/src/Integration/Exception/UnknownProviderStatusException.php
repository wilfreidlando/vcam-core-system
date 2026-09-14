<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

use Core\Support\Integration\Provider\ProviderCode;
use Throwable;

/**
 * Raised when a provider response contains a status string the adapter
 * does not recognise.
 *
 * Each adapter maps its own vocabulary; this exception exists so the
 * adapter can fail loudly when a provider introduces a new status
 * without telling anyone — far better than silently treating an unknown
 * status as a permanent failure (or worse, as success).
 *
 * The provider code and the raw status are carried as public readonly
 * properties so callers can attribute the failure to a specific
 * integration without parsing the exception message.
 */
final class UnknownProviderStatusException extends ProviderException
{
    public function __construct(
        string $message,
        ProviderCode $providerCode,
        /** The raw status string the adapter received from the provider. */
        public readonly string $rawStatus,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $providerCode, httpStatus: null, previous: $previous);
    }

    public static function forValue(ProviderCode $code, string $raw): self
    {
        return new self(
            sprintf(
                'Provider "%s" returned an unrecognised status value: "%s".',
                $code->toString(),
                $raw,
            ),
            $code,
            $raw,
        );
    }
}
