<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\CircuitBreaker;

use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\CircuitBreaker\InMemoryCircuitBreaker;
use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Tests\Unit\Integration\Support\FakeClock;
use DateInterval;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryCircuitBreakerTest extends TestCase
{
    private const PROVIDER = 'mtn-momo';

    #[Test]
    public function guard_is_a_noop_when_closed(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 2),
        );

        $breaker->guard(ProviderCode::fromString(self::PROVIDER));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function breaker_opens_after_threshold_consecutive_failures(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 3, openDurationSeconds: 60),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        $breaker->recordFailure($code);
        $breaker->recordFailure($code);
        self::assertFalse($breaker->isOpen($code));

        $breaker->recordFailure($code);
        self::assertTrue($breaker->isOpen($code));
    }

    #[Test]
    public function open_breaker_refuses_calls(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 1, openDurationSeconds: 60),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        $breaker->recordFailure($code);

        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage(self::PROVIDER);
        $breaker->guard($code);
    }

    #[Test]
    public function open_breaker_transitions_to_half_open_after_cooldown(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 1, openDurationSeconds: 60),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        $breaker->recordFailure($code);
        self::assertTrue($breaker->isOpen($code));

        $clock->advance(new DateInterval('PT61S'));

        // Guard no longer throws — half-open.
        $breaker->guard($code);
        self::assertFalse($breaker->isOpen($code));
    }

    #[Test]
    public function success_in_half_open_closes_the_breaker(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 5, openDurationSeconds: 60),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        $breaker->recordFailure($code);
        $clock->advance(new DateInterval('PT61S'));
        $breaker->guard($code); // half-open

        $breaker->recordSuccess($code);

        // Closed — and the failure counter has been reset to zero.
        self::assertFalse($breaker->isOpen($code));

        // A single failure does not reopen the breaker (threshold 5).
        $breaker->recordFailure($code);
        self::assertFalse($breaker->isOpen($code));
    }

    #[Test]
    public function failure_in_half_open_reopens_immediately(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 1, openDurationSeconds: 60),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        $breaker->recordFailure($code);
        $clock->advance(new DateInterval('PT61S'));
        $breaker->guard($code); // half-open

        $breaker->recordFailure($code);

        self::assertTrue($breaker->isOpen($code));
    }

    #[Test]
    public function breakers_are_per_provider(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 1),
        );

        $breaker->recordFailure(ProviderCode::fromString('mtn-momo'));
        self::assertTrue($breaker->isOpen(ProviderCode::fromString('mtn-momo')));
        self::assertFalse($breaker->isOpen(ProviderCode::fromString('cinetpay')));
    }

    #[Test]
    public function configure_replaces_the_policy(): void
    {
        $clock = new FakeClock;
        $breaker = new InMemoryCircuitBreaker(
            $clock,
            new CircuitBreakerPolicy(failureThreshold: 99),
        );
        $code = ProviderCode::fromString(self::PROVIDER);

        // After configure, the new threshold applies to subsequent calls.
        $breaker->configure(new CircuitBreakerPolicy(failureThreshold: 1));
        $breaker->recordFailure($code);

        self::assertTrue($breaker->isOpen($code));
    }
}
