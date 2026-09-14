<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

use Core\Support\Integration\Configuration\ApiKeyPlacement;
use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;

/**
 * API-key authentication, with two placements:
 *
 *  - **Header** (default): the key rides a dedicated header. The header
 *    name comes from `AuthStrategy::apiKeyHeader` (default `X-Api-Key`).
 *    Most providers we integrate follow this pattern — CinetPay
 *    (`X-Token`), MTN MoMo (`Ocp-Apim-Subscription-Key`), etc.
 *
 *  - **Body**: the key is added to the request payload under the field
 *    name from `AuthStrategy::apiKeyBodyField`. Used by providers that
 *    expect the credential inside the JSON body (or as a form field).
 *    The body is required to be an associative array.
 *
 * No I/O, no caching, no expiration — API keys are static until the
 * operator rotates them.
 */
final class ApiKeyAuthProvider implements AuthProvider
{
    public function supports(AuthKind $kind): bool
    {
        return $kind === AuthKind::ApiKey;
    }

    public function apply(
        ProviderCode $code,
        AuthStrategy $strategy,
        Credentials $credentials,
        array $existingHeaders,
        mixed $currentBody,
    ): AuthApplyResult {
        if ($credentials->apiKey === null || $credentials->apiKey === '') {
            throw new InvalidArgumentException(sprintf(
                'API key auth for "%s" requires a non-empty api_key credential.',
                $code->toString(),
            ));
        }

        if ($strategy->apiKeyPlacement === ApiKeyPlacement::Body) {
            $bodyField = $strategy->apiKeyBodyField;
            if ($bodyField === null || $bodyField === '') {
                throw new InvalidArgumentException(sprintf(
                    'API key auth for "%s" is configured for body placement but '.
                    'AuthStrategy.api_key_body_field is missing.',
                    $code->toString(),
                ));
            }
            if (! is_array($currentBody)) {
                throw new InvalidArgumentException(sprintf(
                    'API key auth for "%s" cannot be placed in the body: '.
                    'the body is not an array.',
                    $code->toString(),
                ));
            }

            return new AuthApplyResult(
                headers: $existingHeaders,
                body: [$bodyField => $credentials->apiKey] + $currentBody,
            );
        }

        $header = $strategy->apiKeyHeader ?? 'X-Api-Key';

        return new AuthApplyResult(
            headers: $existingHeaders + [$header => $credentials->apiKey],
            body: null,
        );
    }
}
