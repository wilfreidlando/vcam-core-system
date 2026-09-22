<?php

declare(strict_types=1);

use Core\Webhook\Domain\Model\WebhookRetryPolicy;

it('computes an exponential backoff capped at max_delay_seconds', function (): void {
    $policy = new WebhookRetryPolicy(maxAttempts: 8, baseDelaySeconds: 30, multiplier: 3, maxDelaySeconds: 21_600);
    $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

    expect($policy->nextRetryAt(1, $now)?->getTimestamp())->toBe($now->getTimestamp() + 30)
        ->and($policy->nextRetryAt(2, $now)?->getTimestamp())->toBe($now->getTimestamp() + 90)
        ->and($policy->nextRetryAt(3, $now)?->getTimestamp())->toBe($now->getTimestamp() + 270)
        // attempt 7 would be 30*3^6 = 21870s, capped at 21600s.
        ->and($policy->nextRetryAt(7, $now)?->getTimestamp())->toBe($now->getTimestamp() + 21_600);
});

it('returns null once max_attempts is reached, signalling the DLQ', function (): void {
    $policy = new WebhookRetryPolicy(maxAttempts: 3);
    $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

    expect($policy->nextRetryAt(1, $now))->not->toBeNull()
        ->and($policy->nextRetryAt(2, $now))->not->toBeNull()
        ->and($policy->nextRetryAt(3, $now))->toBeNull('the 3rd failed attempt exhausts a max of 3')
        ->and($policy->nextRetryAt(4, $now))->toBeNull();
});

it('returns null immediately on a permanent failure, even on the first attempt (ADR-0055)', function (): void {
    $policy = new WebhookRetryPolicy(maxAttempts: 8);
    $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

    // A 405 on the very first attempt — the receiver explicitly rejected
    // the request. Burning 7 more attempts over ~2 hours before giving up
    // only delays the operator noticing. The delivery goes to the DLQ on
    // attempt 1 with full attempt budget unused.
    expect($policy->nextRetryAt(1, $now, isPermanentFailure: true))->toBeNull()
        ->and($policy->nextRetryAt(4, $now, isPermanentFailure: true))->toBeNull()
        ->and($policy->nextRetryAt(7, $now, isPermanentFailure: true))->toBeNull();
});

it('treats 408 and 429 as transient even though they are 4xx', function (): void {
    // The classifier itself decides this; the policy just trusts the
    // boolean. So we set isPermanentFailure: false here, mirroring
    // what `HttpStatusClassifier::isPermanentFailure(408|429)` returns.
    $policy = new WebhookRetryPolicy(maxAttempts: 3);
    $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

    expect($policy->nextRetryAt(1, $now, isPermanentFailure: false))->not->toBeNull()
        ->and($policy->nextRetryAt(1, $now, isPermanentFailure: true))->toBeNull();
});
