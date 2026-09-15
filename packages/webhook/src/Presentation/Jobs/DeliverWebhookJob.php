<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Jobs;

use Core\Webhook\Contracts\DeliverWebhook;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\WebhookDeliveryOutcomeStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery attempt. On a transient failure it `release()`s itself for
 * the backoff `DeliverWebhookUseCase` computed — Horizon's own retry
 * mechanism, not a hand-rolled scheduler (ADR-0040 §3). `$tries` is left
 * high because the use case, not this job, is the authority on when the
 * delivery is exhausted: a job attempt count that ran out before the
 * Domain's `WebhookRetryPolicy` did would silently truncate the DLQ policy.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 50;

    public function __construct(
        private readonly DeliverWebhookCommand $command,
    ) {}

    public function handle(DeliverWebhook $deliverWebhook): void
    {
        $outcome = $deliverWebhook->deliver($this->command);

        if ($outcome->status === WebhookDeliveryOutcomeStatus::RetryScheduled && $outcome->retryAfterSeconds !== null) {
            $this->release($outcome->retryAfterSeconds);
        }
    }
}
