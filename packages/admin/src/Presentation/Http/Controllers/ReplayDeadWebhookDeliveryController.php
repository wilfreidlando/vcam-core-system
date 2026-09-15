<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A not-found or not-dead delivery raises a `RuntimeException` from
 * `ReplayWebhookDelivery` (webhook's Contracts port stays Domain-free —
 * G3c) — this controller does not catch it: `bootstrap/app.php` already
 * maps the concrete `WebhookDeliveryNotFound`/`WebhookDeliveryNotDead`
 * exceptions to RFC 7807 responses for every caller, `webhook:replay`
 * included.
 */
final class ReplayDeadWebhookDeliveryController
{
    public function __construct(
        private readonly ReplayWebhookDelivery $replayWebhookDelivery,
        private readonly QueueWebhookDelivery $queueWebhookDelivery,
    ) {}

    /**
     * Replay a dead webhook delivery as a fresh attempt.
     *
     * Creates a new delivery row and queues it for immediate delivery; the
     * original dead row is kept for audit. Requires `webhooks:replay`.
     */
    public function __invoke(string $deliveryId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('webhooks:replay')) {
            return ProblemDetails::forbidden('The webhooks:replay permission is required.');
        }

        $view = $this->replayWebhookDelivery->replay(new ReplayWebhookDeliveryCommand($deliveryId));

        $this->queueWebhookDelivery->queue(new DeliverWebhookCommand($view->newDeliveryId));

        return new JsonResponse(['id' => $view->newDeliveryId], 201);
    }
}
