<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

/**
 * Permanent failure: 4xx other than 401/403/408/429 — retrying will not
 * change the outcome. The caller should NOT retry.
 *
 * Typical causes: invalid request payload (the adapter sent something the
 * provider rejected), unknown resource, forbidden operation. The error
 * message carries the provider's diagnostic; HTTP status is preserved for
 * log correlation.
 */
final class ProviderPermanentError extends ProviderException {}
