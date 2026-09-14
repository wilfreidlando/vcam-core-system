<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use InvalidArgumentException;

/**
 * Circuit breaker policy for the provider.
 *
 * After `failureThreshold` consecutive failures, the breaker opens and
 * short-circuits further calls for `openDurationSeconds` (no HTTP request
 * is issued). After the cooldown, `halfOpenProbes` trial requests are
 * allowed; their success closes the breaker, their failure reopens it.
 *
 * The state itself lives in a `CircuitBreaker` implementation (in-memory
 * by default). The policy is just the configuration.
 */
final readonly class CircuitBreakerPolicy
{
    public function __construct(
        public int $failureThreshold = 5,
        public int $openDurationSeconds = 60,
        public int $halfOpenProbes = 1,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $threshold = self::intOr($data, 'failure_threshold', 5);
        if ($threshold < 1) {
            throw new InvalidArgumentException('CircuitBreakerPolicy.failure_threshold must be >= 1.');
        }
        $openDuration = self::intOr($data, 'open_duration_seconds', 60);
        if ($openDuration < 1) {
            throw new InvalidArgumentException('CircuitBreakerPolicy.open_duration_seconds must be >= 1.');
        }
        $probes = self::intOr($data, 'half_open_probes', 1);
        if ($probes < 1) {
            throw new InvalidArgumentException('CircuitBreakerPolicy.half_open_probes must be >= 1.');
        }

        return new self(
            failureThreshold: $threshold,
            openDurationSeconds: $openDuration,
            halfOpenProbes: $probes,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intOr(array $data, string $key, int $default): int
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        if (! is_numeric($data[$key])) {
            throw new InvalidArgumentException(sprintf('CircuitBreakerPolicy.%s must be an integer.', $key));
        }

        return (int) $data[$key];
    }
}
