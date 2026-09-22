<?php

declare(strict_types=1);

namespace Core\Support\Http;

/**
 * Classifies an HTTP response status into retryable vs not — shared
 * between the registered-webhook delivery plane (`Core\Webhook`) and the
 * per-payment callback plane (`Core\Payment\Infrastructure\Delivery`),
 * so a single rule applies to both: a `4xx` response other than
 * `408 Request Timeout` / `429 Too Many Requests` is a permanent
 * caller-side failure (the receiver explicitly rejected the request,
 * no amount of retry will change that) and should skip the retry
 * budget entirely.
 *
 * Concretely:
 * - `null` status -> transient. HTTP libraries report `null` only when
 *   they couldn't connect at all (DNS, TLS handshake, connection
 *   refused, timeout). Caller-side endpoint issues are surfaced by a
 *   real status code, so `null` is unambiguous: try again.
 * - `5xx` -> transient. Server problem, may resolve.
 * - `408` -> transient. RFC 7231 explicitly: "the client did not
 *   produce a request within the time the server was prepared to
 *   wait" — usually a load-shedding signal, retry-friendly.
 * - `429` -> transient. The receiver is asking us to slow down.
 *   Retry with whatever backoff we already use.
 * - Any other `4xx` (400, 401, 403, 404, 405, 410, 415, 422, …) ->
 *   permanent. The receiver has understood the request and rejected
 *   it on a defect the request itself carries (or, for 401/403/404/
 *   410/405, on a configuration issue that retrying will not fix).
 *
 * See ADR-0055 for the rationale and the deliberate split between
 * "permanent failure goes to dead/DLQ" (webhook plane) and
 * "permanent failure calls `fail()` immediately" (payment callback
 * plane — no DLQ, ADR-0038 §4 trade-off).
 */
final class HttpStatusClassifier
{
    /**
     * @see HttpStatusClassifier docblock for the full classification table
     */
    public static function isPermanentFailure(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return false;
        }

        if ($httpStatus === 408 || $httpStatus === 429) {
            return false;
        }

        return $httpStatus >= 400 && $httpStatus < 500;
    }

    /**
     * @see HttpStatusClassifier docblock for the full classification table
     */
    public static function isTransientFailure(?int $httpStatus): bool
    {
        return ! self::isPermanentFailure($httpStatus);
    }
}
