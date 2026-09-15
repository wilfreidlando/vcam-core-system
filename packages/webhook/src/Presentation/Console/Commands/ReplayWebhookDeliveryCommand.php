<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Console\Commands;

use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand as ReplayCommand;
use Core\Webhook\Presentation\Jobs\DeliverWebhookJob;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Operator-only replay (ADR-0040 §7): the Core Admin control ADR-0009
 * describes does not exist before Lot 6.
 */
final class ReplayWebhookDeliveryCommand extends Command
{
    protected $signature = 'webhook:replay {delivery : The dead WebhookDelivery id}';

    protected $description = 'Replay a dead webhook delivery as a fresh attempt.';

    public function handle(ReplayWebhookDelivery $replayWebhookDelivery): int
    {
        /** @var string $deliveryId */
        $deliveryId = $this->argument('delivery');

        try {
            $view = $replayWebhookDelivery->replay(new ReplayCommand($deliveryId));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        DeliverWebhookJob::dispatch(new DeliverWebhookCommand($view->newDeliveryId));

        $this->info(sprintf('Replayed as delivery "%s", dispatched for delivery.', $view->newDeliveryId));

        return self::SUCCESS;
    }
}
