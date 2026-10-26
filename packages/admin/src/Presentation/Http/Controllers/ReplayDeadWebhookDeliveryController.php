<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\AuthorizesInScope;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\GetWebhookDeliveryDetails;
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
    use AuthorizesInScope;

    public function __construct(
        private readonly ReplayWebhookDelivery $replayWebhookDelivery,
        private readonly GetWebhookDeliveryDetails $deliveryDetails,
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
        $scope = $this->permittedScope($operator, 'webhooks:replay');
        if ($scope === null) {
            return ProblemDetails::forbidden('The webhooks:replay permission is required.');
        }

        Validator::make(['deliveryId' => $deliveryId], ['deliveryId' => 'uuid'])->validate();

        // ADR-0069 §2 / S-04 — the delivery is resolved inside the scope
        // before it is replayed: replaying a foreign delivery would re-send
        // another tenant's payload to its receiver. Out of scope is exactly
        // as absent as missing.
        $delivery = $this->deliveryDetails->forDelivery($deliveryId, $scope);
        if ($delivery === null) {
            return ProblemDetails::make('webhook-delivery-not-found', 'Webhook delivery not found', 404, sprintf('Webhook delivery %s does not exist.', $deliveryId));
        }

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
            projectId: $delivery->projectId,
        ));

        return new JsonResponse(['id' => $view->newDeliveryId], 201);
    }
}
