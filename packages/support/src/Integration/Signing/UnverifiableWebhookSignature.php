<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

use RuntimeException;

/**
 * Thrown by `WebhookSignatureVerifier` when the signature does not match
 * what the foundation would compute.
 *
 * Carries no information about the expected or computed signature —
 * leaking either side would weaken the verification. The exception
 * message only identifies the failing webhook by what the caller passed
 * (typically a provider code).
 */
final class UnverifiableWebhookSignature extends RuntimeException {}
