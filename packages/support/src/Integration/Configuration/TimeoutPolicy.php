<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use InvalidArgumentException;

/**
 * Bounded timeouts for an outbound HTTP call.
 *
 * Three independent clocks because they fail differently:
 *   - `connectSeconds` — DNS / TCP / TLS handshake. Slow here means a network
 *     or provider-side firewall issue; retrying is appropriate.
 *   - `requestSeconds` — full request lifecycle including server processing.
 *     Slow here may be provider-side load; retrying may be appropriate.
 *   - `totalSeconds`   — absolute cap including retries. Bounds the worst
 *     case end-to-end so a worker can never be blocked past this.
 */
final readonly class TimeoutPolicy
{
    public function __construct(
        public float $connectSeconds = 2.0,
        public float $requestSeconds = 10.0,
        public float $totalSeconds = 30.0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connectSeconds: self::positiveFloat($data, 'connect', 2.0),
            requestSeconds: self::positiveFloat($data, 'request', 10.0),
            totalSeconds: self::positiveFloat($data, 'total', 30.0),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function positiveFloat(array $data, string $key, float $default): float
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (! is_numeric($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'TimeoutPolicy.%s must be a number.',
                $key,
            ));
        }
        $value = (float) $data[$key];
        if ($value <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'TimeoutPolicy.%s must be > 0 seconds (got %s).',
                $key,
                $value,
            ));
        }

        return $value;
    }
}
