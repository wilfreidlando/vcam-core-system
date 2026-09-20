<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook DLQ admin: list dead deliveries + replay action.
 *
 * The replay button on each row uses Alpine.js for a confirmation
 * dialog (see webhook-deliveries/dead.blade.php) — the POST to
 * `/webhook-deliveries/{id}/replay` is what this controller serves.
 */
final class WebhookDeliveriesController
{
    public function __construct(
        private readonly ListDeadWebhookDeliveries $listDead,
        private readonly ReplayWebhookDelivery $replay,
        private readonly QueueWebhookDelivery $queue,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('webhooks:read')) {
            abort(403, 'The webhooks:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $projectId = (string) $request->query('project_id', '');

        if ($projectId !== '') {
            Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();
        }

        $pageResult = $this->listDead->list(new ListDeadWebhookDeliveriesCommand(
            projectId: $projectId === '' ? null : $projectId,
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.webhook-deliveries.dead', [
            'title' => 'Webhook DLQ',
            'deliveries' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['project_id' => $projectId],
        ]);
    }

    public function replay(string $delivery, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('webhooks:replay')) {
            abort(403, 'The webhooks:replay permission is required.');
        }

        // Validate UUID shape before delegating — otherwise the contract
        // throws a 404 which is rendered as problem+json (correct for API
        // clients, confusing for the web UI which would rather see a
        // friendlier redirect).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $delivery) !== 1) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Invalid delivery id.']);
        }

        try {
            $result = $this->replay->replay(new ReplayWebhookDeliveryCommand(
                deliveryId: $delivery,
            ));
        } catch (WebhookDeliveryNotFound) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Delivery not found.']);
        } catch (WebhookDeliveryNotDead) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Delivery is not dead; no need to replay.']);
        }

        // The JSON API's equivalent controller queues the new delivery
        // for actual dispatch after replay() creates its row — this web
        // controller used to skip that call entirely, so a "replayed"
        // delivery was created but never attempted (review finding,
        // Lot 6 follow-up).
        $this->queue->queue(new DeliverWebhookCommand($result->newDeliveryId));

        // The JSON API's equivalent controller audits this action; this
        // web controller used to skip it entirely, leaving no audit
        // trail for a replay triggered from the console (security
        // review finding).
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_delivery.replayed',
            resource: 'webhook_delivery:'.$delivery,
            before: null,
            after: ['new_delivery_id' => $result->newDeliveryId],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.webhook-deliveries.dead.web')
            ->with('status', 'Replay queued — new delivery id: '.substr($result->newDeliveryId, 0, 8).'…');
    }
}
