<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Console\Commands;

use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\SweepStalledWebhookDeliveries;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes (audit 2026-10-03 §4.3). Re-dispatches
 * `pending` deliveries overdue by more than `--overdue` minutes. A
 * duplicate dispatch is harmless: `DeliverWebhookUseCase` ignores a job
 * that runs before the delivery's `next_retry_at`, and the job holds a
 * per-delivery lock while sending.
 */
final class SweepStalledWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhook:sweep {--overdue=15 : Minutes past due before a pending delivery counts as stalled} {--limit=500}';

    protected $description = 'Re-dispatch pending webhook deliveries whose queued job was lost.';

    public function handle(SweepStalledWebhookDeliveries $sweep, QueueWebhookDelivery $queue): int
    {
        $ids = $sweep->sweep((int) $this->option('overdue'), (int) $this->option('limit'));

        foreach ($ids as $id) {
            $queue->queue(new DeliverWebhookCommand($id));
        }

        $this->info(sprintf('Re-dispatched %d stalled webhook deliver%s.', count($ids), count($ids) === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}
