<?php

declare(strict_types=1);

namespace Core\Support\Integration\Redaction;

/**
 * Replaces secret values in structured payloads with `***REDACTED***`
 * before they reach the log layer.
 *
 * Why a dedicated redactor instead of `Log::info($payload)`:
 *  - The `SensitiveKeySet` is the single source of truth for what counts
 *    as secret. Operators can extend it for provider-specific names
 *    without touching the call sites.
 *  - The redactor walks arrays **recursively** and matches key names
 *    case-insensitively against substrings (`client_secret`,
 *    `CLIENT_SECRET`, `Client-Secret` all match).
 *  - The redactor handles the most common leak vectors: top-level keys,
 *    nested keys, headers (case-insensitive on the key).
 *
 * Non-secret values pass through unchanged, so operators keep the
 * diagnostic value of the log line (status code, latency, response
 * shape) without losing safety on the secret value.
 *
 * Idempotent: applying the redactor twice yields the same result as
 * applying it once (a redacted value is still safe to redact).
 */
final class PayloadRedactor
{
    public const PLACEHOLDER = '***REDACTED***';

    public function __construct(
        private readonly SensitiveKeySet $keys = new SensitiveKeySet,
    ) {}

    /**
     * Redact a structured payload (typically the response body or a
     * context array for a log line).
     */
    public function redact(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return $this->redactArray($payload);
        }

        return $payload;
    }

    /**
     * Redact an HTTP header bag. Keys are matched case-insensitively.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $sensitive = [];
        foreach ($this->keys->headerNames() as $name) {
            $sensitive[strtolower($name)] = true;
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            $out[$name] = isset($sensitive[$lower])
                ? self::PLACEHOLDER
                : $value;
        }

        return $out;
    }

    /**
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function redactArray(array $payload): array
    {
        $substrings = $this->keys->keySubstrings();
        $substringsLower = array_map('strtolower', $substrings);

        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->keyIsSensitive(strtolower($key), $substringsLower)) {
                $out[$key] = self::PLACEHOLDER;

                continue;
            }
            $out[$key] = is_array($value)
                ? $this->redactArray($value)
                : $value;
        }

        return $out;
    }

    /**
     * @param list<string> $substringsLower
     */
    private function keyIsSensitive(string $lowerKey, array $substringsLower): bool
    {
        foreach ($substringsLower as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
