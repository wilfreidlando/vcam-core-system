<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\MyCoolPay;

use Core\Payment\Domain\Port\PaymentCallbackAuthenticator;

/**
 * Verifies that an inbound `/webhooks/my-coolpay` request genuinely came
 * from MyCoolPay, per their documented procedure (API docs §7.5 "Callback
 * requests integrity" — https://documenter.getpostman.com/view/17178321/UV5ZCx8f):
 *
 *   1. The request's source IP must be MyCoolPay's server (15.236.140.89
 *      at the time of writing — configurable, see `config/providers.php`
 *      `extras.callback_trusted_ips`).
 *   2. The payload's `signature` field must equal
 *      `md5(transaction_ref + transaction_type + transaction_amount +
 *      transaction_currency + transaction_operator + private_key)`
 *      (`+` = string concatenation, numbers stringified without
 *      insignificant trailing zeros).
 *
 * Security review finding: this controller used to skip both checks
 * entirely — the only gate was comparing the payload's `application`
 * field against our configured public key, which MyCoolPay's own docs
 * confirm is not a secret ("you won't run any security risk by exposing
 * it"). It also travels in every checkout paylink shown to the paying
 * customer, so it identifies our application but proves nothing about
 * the caller. This class is the actual authenticity gate; the
 * application-key comparison in the controller stays as a secondary,
 * informational cross-tenant sanity check.
 */
final class MyCoolPayCallbackAuthenticator implements PaymentCallbackAuthenticator
{
    /**
     * @param list<string> $trustedIps
     */
    public function __construct(
        private readonly array $trustedIps,
    ) {}

    public function isFromTrustedIp(?string $requestIp): bool
    {
        if ($this->trustedIps === []) {
            // Deliberately permissive when no allowlist is configured —
            // e.g. sandbox/local testing, per `config/providers.php`.
            // The signature check remains mandatory and is never
            // bypassable this way.
            return true;
        }

        return $requestIp !== null && in_array($requestIp, $this->trustedIps, true);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function hasValidSignature(array $body, string $privateKey): bool
    {
        $signature = $body['signature'] ?? null;
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = md5(
            self::stringify($body['transaction_ref'] ?? null)
            .self::stringify($body['transaction_type'] ?? null)
            .self::stringify($body['transaction_amount'] ?? null)
            .self::stringify($body['transaction_currency'] ?? null)
            .self::stringify($body['transaction_operator'] ?? null)
            .$privateKey,
        );

        return hash_equals($expected, $signature);
    }

    /**
     * MyCoolPay's own formula note: "Numbers should be converted to
     * strings without non significant zeros" — `json_decode` already
     * gives us a plain `int` for a whole-number JSON value like `100`, so
     * a direct string cast matches their expected format. A `float`
     * (only possible if MyCoolPay ever sends a decimal amount) is
     * trimmed the same way.
     */
    private static function stringify(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return is_string($value) ? $value : '';
    }
}
