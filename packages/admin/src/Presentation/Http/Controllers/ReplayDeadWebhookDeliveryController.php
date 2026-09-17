<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
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
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
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

        Validator::make(['deliveryId' => $deliveryId], ['deliveryId' => 'uuid'])->validate();

        $view = $this->replayWebhookDelivery->replay(new ReplayWebhookDeliveryCommand($deliveryId));

        $this->queueWebhookDelivery->queue(new DeliverWebhookCommand($view->newDeliveryId));

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_delivery.replayed',
            resource: 'webhook_delivery:'.$deliveryId,
            before: null,
            after: ['new_delivery_id' => $view->newDeliveryId],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return new JsonResponse(['id' => $view->newDeliveryId], 201);
    }
}
