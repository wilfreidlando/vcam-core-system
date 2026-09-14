<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Support;

use Core\Support\Integration\CircuitBreaker\CircuitBreaker;
use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Circuit breaker that counts manually. Tests use it to drive the
 * `ExternalApiCaller` into specific states (open, closed) without
 * depending on the real state-machine timing.
 */
final class FakeCircuitBreaker implements CircuitBreaker
{
    public int $successes = 0;

    public int $failures = 0;

    /** @var array<string, bool> */
    private array $open = [];

    public function __construct() {}

    public function openFor(ProviderCode $code): void
    {
        $this->open[$code->toString()] = true;
    }

    public function guard(ProviderCode $code): void
    {
        if (($this->open[$code->toString()] ?? false) === true) {
            throw new \Core\Support\Integration\CircuitBreaker\CircuitOpenException($code);
        }
    }

    public function recordSuccess(ProviderCode $code): void
    {
        $this->successes++;
        $this->open[$code->toString()] = false;
    }

    public function recordFailure(ProviderCode $code): void
    {
        $this->failures++;
    }

    public function isOpen(ProviderCode $code): bool
    {
        return ($this->open[$code->toString()] ?? false) === true;
    }

    public function configure(CircuitBreakerPolicy $policy): void
    {
        // No-op for the fake.
    }
}
