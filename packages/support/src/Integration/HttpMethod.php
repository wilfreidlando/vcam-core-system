<?php

declare(strict_types=1);

namespace Core\Support\Integration;

/**
 * HTTP methods recognised by the integration foundation.
 *
 * Modelled as an enum (not a class constant set) so the `match` expressions
 * in `ExternalApiCallerImpl` and `WebhookSignatureVerifier` are exhaustive
 * at the type level — adding a new method is a PHPStan failure waiting to
 * happen, exactly what we want for security-sensitive code.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
}
