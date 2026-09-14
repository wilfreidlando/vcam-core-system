<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

use RuntimeException;

/**
 * Thrown by `WebhookSignatureVerifier` when the timestamp is outside the
 * allowed clock skew window.
 *
 * Distinct from `UnverifiableWebhookSignature` so the controller can
 * react differently (e.g. log the skew, alert on misconfigured clocks on
 * either side) without parsing the message.
 */
final class WebhookTimestampOutOfRange extends RuntimeException {}
