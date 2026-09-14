<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use InvalidArgumentException;

/**
 * Bounded retry policy for transient failures.
 *
 * The retry budget is small on purpose: a flaky network gets 3 chances, not
 * 30. After `maxAttempts`, the call surfaces as `ProviderTransientError` so
 * the caller (or a saga at Lot 3) can decide what to do — never an
 * unbounded retry loop.
 *
 * The delay between attempts is computed as:
 *   baseDelayMs * 2^attempt ± (baseDelayMs * jitter)
 *
 * bounded by `maxDelayMs`. Jitter prevents thundering-herd retry storms
 * when a provider briefly degrades and every worker retries at the same
 * moment.
 */
final readonly class RetryPolicy
{
    /**
     * @param list<int> $retryableStatuses HTTP statuses that trigger a retry.
     *                                     Defaults to transient server-side
     *                                     conditions (502/503/504/429).
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 5000,
        public float $jitter = 0.2,
        public array $retryableStatuses = [502, 503, 504, 429],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $maxAttempts = self::intOr($data, 'max_attempts', 3);
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('RetryPolicy.max_attempts must be >= 1.');
        }
        $baseDelayMs = self::intOr($data, 'base_delay_ms', 200);
        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException('RetryPolicy.base_delay_ms must be >= 0.');
        }
        $maxDelayMs = self::intOr($data, 'max_delay_ms', 5000);
        if ($maxDelayMs < $baseDelayMs) {
            throw new InvalidArgumentException('RetryPolicy.max_delay_ms must be >= base_delay_ms.');
        }
        $jitterRaw = $data['jitter'] ?? 0.2;
        if (! is_numeric($jitterRaw)) {
            throw new InvalidArgumentException('RetryPolicy.jitter must be a number.');
        }
        $jitter = (float) $jitterRaw;
        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new InvalidArgumentException('RetryPolicy.jitter must be in [0.0, 1.0].');
        }
        $statuses = $data['retryable_statuses'] ?? [502, 503, 504, 429];
        if (! is_array($statuses)) {
            throw new InvalidArgumentException('RetryPolicy.retryable_statuses must be a list.');
        }
        $normalised = [];
        foreach ($statuses as $value) {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('RetryPolicy.retryable_statuses entries must be integers.');
            }
            $normalised[] = (int) $value;
        }
        /** @var list<int> $statuses */
        $statuses = $normalised;

        return new self(
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            maxDelayMs: $maxDelayMs,
            jitter: $jitter,
            retryableStatuses: $statuses,
        );
    }

    /**
     * Compute the delay (milliseconds) before the next attempt, given how
     * many attempts have already been made (0 = first retry).
     *
     * Exposed for testability and for adapters that need to log or display
     * the backoff. Pure function: no I/O, no clock.
     */
    public function delayMsForAttempt(int $attempt, ?int $randomJitterMs = null): int
    {
        if ($attempt < 0) {
            throw new InvalidArgumentException('attempt must be >= 0.');
        }

        $base = $this->baseDelayMs * (2 ** $attempt);
        $jitterMs = $randomJitterMs
            ?? (int) round($base * $this->jitter);

        // Random jitter can be negative or positive; the caller passes a
        // signed value (or we generate one with a deterministic spread).
        $delay = (int) round($base + $jitterMs);

        if ($delay < 0) {
            return 0;
        }

        return min($delay, $this->maxDelayMs);
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
            throw new InvalidArgumentException(sprintf('RetryPolicy.%s must be an integer.', $key));
        }

        return (int) $data[$key];
    }
}
