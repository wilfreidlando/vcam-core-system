<?php

declare(strict_types=1);

namespace Core\Support\Integration\CircuitBreaker;

use Core\Support\Clock;
use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * In-process circuit breaker.
 *
 * State per provider:
 *  - `closed`        → calls flow through
 *  - `open`          → calls are refused until `openUntil` is in the past
 *  - `half_open`     → up to `halfOpenProbes` trial calls are allowed;
 *                      one success closes, one failure reopens
 *
 * The state and counters live in plain PHP arrays, so each PHP worker
 * process has its own view. That is acceptable as a default: workers do
 * not synchronize failure counts, but they also cannot coordinate a
 * coordinated outage. For cluster-wide coordination the application shell
 * rebinds `CircuitBreaker` to a Valkey-backed implementation.
 *
 * Concurrency: the only mutation happens inside the methods of this class.
 * PHP's single-threaded per-request model means we don't need locking
 * within one process. Two concurrent failures from two requests each
 * increment their own counter — this is acceptable because the threshold
 * is small.
 */
final class InMemoryCircuitBreaker implements CircuitBreaker
{
    private CircuitBreakerPolicy $policy;

    /** @var array<string, array{state: string, failures: int, successes: int, openedAt: ?int}> */
    private array $states = [];

    public function __construct(
        private readonly Clock $clock,
        ?CircuitBreakerPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new CircuitBreakerPolicy;
    }

    public function configure(CircuitBreakerPolicy $policy): void
    {
        $this->policy = $policy;
    }

    public function guard(ProviderCode $code): void
    {
        $state = $this->stateOf($code);

        if ($state['state'] === 'open') {
            $openedAt = $state['openedAt'];
            if ($openedAt === null) {
                // Defensive: open without openedAt is impossible by construction.
                return;
            }
            $now = $this->clock->now()->getTimestamp();
            if ($now - $openedAt >= $this->policy->openDurationSeconds) {
                $this->states[$code->toString()] = [
                    'state' => 'half_open',
                    'failures' => 0,
                    'successes' => 0,
                    'openedAt' => null,
                ];

                return;
            }
            throw new CircuitOpenException($code);
        }

        // closed or half_open with available probes: pass through.
    }

    public function recordSuccess(ProviderCode $code): void
    {
        $state = $this->stateOf($code);
        if ($state['state'] === 'half_open') {
            // One success in half-open closes the breaker.
            $this->states[$code->toString()] = [
                'state' => 'closed',
                'failures' => 0,
                'successes' => 0,
                'openedAt' => null,
            ];

            return;
        }
        // In closed state, a success resets the failure counter so that
        // long-lived providers don't accumulate unrelated errors.
        $this->states[$code->toString()] = [
            'state' => 'closed',
            'failures' => 0,
            'successes' => $state['successes'] + 1,
            'openedAt' => null,
        ];
    }

    public function recordFailure(ProviderCode $code): void
    {
        $state = $this->stateOf($code);

        if ($state['state'] === 'half_open') {
            // Any failure in half-open reopens the breaker for the full
            // duration. This is the safer default: a flaky trial means
            // the provider is still impaired.
            $this->openBreaker($code);

            return;
        }

        $failures = $state['failures'] + 1;
        if ($failures >= $this->policy->failureThreshold) {
            $this->openBreaker($code);

            return;
        }
        $this->states[$code->toString()] = [
            'state' => 'closed',
            'failures' => $failures,
            'successes' => $state['successes'],
            'openedAt' => null,
        ];
    }

    public function isOpen(ProviderCode $code): bool
    {
        return $this->stateOf($code)['state'] === 'open';
    }

    /**
     * @return array{state: string, failures: int, successes: int, openedAt: ?int}
     */
    private function stateOf(ProviderCode $code): array
    {
        return $this->states[$code->toString()] ?? [
            'state' => 'closed',
            'failures' => 0,
            'successes' => 0,
            'openedAt' => null,
        ];
    }

    private function openBreaker(ProviderCode $code): void
    {
        $this->states[$code->toString()] = [
            'state' => 'open',
            'failures' => $this->policy->failureThreshold,
            'successes' => 0,
            'openedAt' => $this->clock->now()->getTimestamp(),
        ];
    }
}
