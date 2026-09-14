<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Configuration;

use Core\Support\Integration\Configuration\RetryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function sensible_defaults(): void
    {
        $policy = RetryPolicy::fromArray([]);

        self::assertSame(3, $policy->maxAttempts);
        self::assertSame(200, $policy->baseDelayMs);
        self::assertSame(5000, $policy->maxDelayMs);
        self::assertSame(0.2, $policy->jitter);
        self::assertSame([502, 503, 504, 429], $policy->retryableStatuses);
    }

    #[Test]
    public function zero_attempts_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::fromArray(['max_attempts' => 0]);
    }

    #[Test]
    public function negative_base_delay_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::fromArray(['base_delay_ms' => -10]);
    }

    #[Test]
    public function max_delay_below_base_delay_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::fromArray(['base_delay_ms' => 1000, 'max_delay_ms' => 500]);
    }

    #[Test]
    public function jitter_outside_zero_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::fromArray(['jitter' => 1.5]);
    }

    #[Test]
    public function delay_for_attempt_grows_exponentially_without_jitter(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 60_000, jitter: 0.0);

        self::assertSame(100, $policy->delayMsForAttempt(0, 0));
        self::assertSame(200, $policy->delayMsForAttempt(1, 0));
        self::assertSame(400, $policy->delayMsForAttempt(2, 0));
        self::assertSame(800, $policy->delayMsForAttempt(3, 0));
    }

    #[Test]
    public function delay_is_capped_at_max_delay(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 1000, maxDelayMs: 5000, jitter: 0.0);

        self::assertSame(5000, $policy->delayMsForAttempt(10, 0));
    }

    #[Test]
    public function negative_jitter_brings_delay_below_base(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 60_000, jitter: 0.5);

        $delay = $policy->delayMsForAttempt(1, -50);
        self::assertSame(150, $delay); // 200 + (-50)
    }

    #[Test]
    public function delay_never_dips_below_zero(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 60_000, jitter: 0.0);

        $delay = $policy->delayMsForAttempt(0, -1000);

        self::assertSame(0, $delay);
    }
}
