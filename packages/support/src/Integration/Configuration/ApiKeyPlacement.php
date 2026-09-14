<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

/**
 * Where an API-key credential is placed on the outgoing request.
 *
 * `Header` — the conventional case: the key rides a dedicated header
 * (`X-Api-Key`, `X-Token`, `Ocp-Apim-Subscription-Key`, …). The header
 * name comes from `AuthStrategy::apiKeyHeader`.
 *
 * `Body` — some providers (typically form-encoded or JSON) expect the
 * key as part of the request payload itself. The field name comes from
 * `AuthStrategy::apiKeyBodyField`.
 */
enum ApiKeyPlacement: string
{
    case Header = 'header';
    case Body = 'body';
}
