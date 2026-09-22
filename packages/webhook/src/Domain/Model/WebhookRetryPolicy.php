<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use DateInterval;
use DateTimeImmutable;

/**
 * Pure backoff computation — deliberately not `Core\Support\Integration\Configuration\RetryPolicy`
 * (ADR-0040 §3): that policy governs an in-process sleep loop measured in
 * milliseconds, bounded to a handful of attempts within one HTTP call.
 * Webhook retries are persisted (`next_retry_at`), can span hours, and
 * must survive a worker restart — a different shape of problem, computed
 * here and executed by `Horizon`'s job `release()`, not a sleep.
 *
 * `isPermanentFailure` (ADR-0055) is consulted BEFORE the attempt counter:
 * a `4xx` other than `408`/`429` returns `null` immediately, sending the
 * delivery to dead (DLQ) regardless of how many attempts remain. The
 * receiver has explicitly rejected the request on a defect retrying
 * won't fix; burning the remaining budget would only delay the operator
 * noticing it.
 */
final readonly class WebhookRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 8,
        public int $baseDelaySeconds = 30,
        public int $multiplier = 3,
        public int $maxDelaySeconds = 21_600, // 6 hours
    ) {}

    /**
     * @param int $attemptNumber the attempt that just failed (1-based)
     * @param bool $isPermanentFailure true when the last response was a
     *                                 4xx (excluding 408/429) — the
     *                                 delivery goes to dead immediately.
     * @return DateTimeImmutable|null null means the retry budget is exhausted (or
     *                                the last failure was permanent) — the
     *                                delivery moves to `dead` (DLQ) instead.
     */
    public function nextRetryAt(int $attemptNumber, DateTimeImmutable $now, bool $isPermanentFailure = false): ?DateTimeImmutable
    {
        if ($isPermanentFailure) {
            return null;
        }

        if ($attemptNumber >= $this->maxAttempts) {
            return null;
        }

        $delaySeconds = min(
            $this->maxDelaySeconds,
            $this->baseDelaySeconds * ($this->multiplier ** ($attemptNumber - 1)),
        );

        return $now->add(new DateInterval(sprintf('PT%dS', $delaySeconds)));
    }
}
