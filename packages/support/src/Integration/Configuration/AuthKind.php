<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

/**
 * The kind of authentication strategy a provider requires.
 *
 * Decoupled from any provider-specific vocabulary — the socle knows how to
 * build the matching `AuthProvider` for each value, and concrete adapters
 * declare only the kind, never the credentials.
 */
enum AuthKind: string
{
    case Bearer = 'bearer';
    case Basic = 'basic';
    case ApiKey = 'api-key';
}
