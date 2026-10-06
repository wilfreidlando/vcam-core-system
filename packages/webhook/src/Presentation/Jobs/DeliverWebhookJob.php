<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Jobs;

use Core\Webhook\Contracts\DeliverWebhook;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\FailWebhookDelivery;
use Core\Webhook\Contracts\WebhookDeliveryOutcomeStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One delivery attempt. On a transient failure it `release()`s itself for
 * the backoff `DeliverWebhookUseCase` computed — Horizon's own retry
 * mechanism, not a hand-rolled scheduler (ADR-0040 §3). `$tries` is left
 * high because the use case, not this job, is the authority on when the
 * delivery is exhausted: a job attempt count that ran out before the
 * Domain's `WebhookRetryPolicy` did would silently truncate the DLQ policy.
 *
 * Audit 2026-10-03 §4.3: a per-delivery lock serialises concurrent
 * executions (at-least-once queue, `webhook:sweep` re-dispatch), and
 * `failed()` moves the row to the DLQ when the job itself dies, so a
 * delivery can never stay `pending` forever.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 50;

    public function __construct(
        public readonly DeliverWebhookCommand $command,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(DeliverWebhook $deliverWebhook): void
    {
        $lock = Cache::lock('webhook-delivery:'.$this->command->deliveryId, 60);
        if ($lock->get() !== true) {
            // Another worker is sending this very delivery right now.
            $this->release(30);

            return;
        }

        try {
            $outcome = $deliverWebhook->deliver($this->command);
        } finally {
            $lock->release();
        }

        if ($outcome->status === WebhookDeliveryOutcomeStatus::RetryScheduled && $outcome->retryAfterSeconds !== null) {
            $this->release($outcome->retryAfterSeconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FailWebhookDelivery::class)->fail(
            $this->command->deliveryId,
            'Delivery job failed: '.($exception?->getMessage() ?? 'unknown error'),
        );
    }
}
