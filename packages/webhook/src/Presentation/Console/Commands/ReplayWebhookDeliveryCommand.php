<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Console\Commands;

use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand as ReplayCommand;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Operator-only replay (ADR-0040 §7). Since Lot 6 (ADR-0044), the same
 * `ReplayWebhookDelivery` + `QueueWebhookDelivery` pair this command uses is
 * also what the Core Admin operator console's HTTP replay endpoint calls —
 * one code path, two front doors.
 */
final class ReplayWebhookDeliveryCommand extends Command
{
    protected $signature = 'webhook:replay {delivery : The dead WebhookDelivery id}';

    protected $description = 'Replay a dead webhook delivery as a fresh attempt.';

    public function handle(ReplayWebhookDelivery $replayWebhookDelivery, QueueWebhookDelivery $queueWebhookDelivery): int
    {
        /** @var string $deliveryId */
        $deliveryId = $this->argument('delivery');

        try {
            $view = $replayWebhookDelivery->replay(new ReplayCommand($deliveryId));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $queueWebhookDelivery->queue(new DeliverWebhookCommand($view->newDeliveryId));

        $this->info(sprintf('Replayed as delivery "%s", dispatched for delivery.', $view->newDeliveryId));

        return self::SUCCESS;
    }
}
