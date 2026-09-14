<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

/**
 * Symmetric signature algorithm used by the request signer and the webhook
 * verifier. Only algorithms implemented by `Core\Support\Integration` are
 * listed; future additions (e.g. RSA-SHA256, AWS SigV4) get a new case and
 * a new signer implementation.
 */
enum SignatureAlgorithm: string
{
    case HmacSha256 = 'hmac-sha256';
}
