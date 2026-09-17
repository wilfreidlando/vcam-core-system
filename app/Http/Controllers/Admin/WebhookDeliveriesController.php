<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Illuminate\Http\Request;
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
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $projectId = (string) $request->query('project_id', '');

        $page_result = $this->listDead->list(new \Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand(
            projectId: $projectId === '' ? null : $projectId,
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.webhook-deliveries.dead', [
            'title' => 'Webhook DLQ',
            'deliveries' => $page_result->items,
            'total' => $page_result->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['project_id' => $projectId],
        ]);
    }

    public function replay(string $delivery): RedirectResponse
    {
        // Validate UUID shape before delegating — otherwise the contract
        // throws a 404 which is rendered as problem+json (correct for API
        // clients, confusing for the web UI which would rather see a
        // friendlier redirect).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $delivery)) {
            return redirect()->route('admin.webhook-deliveries.dead')
                ->withErrors(['id' => 'Invalid delivery id.']);
        }

        try {
            $result = $this->replay->replay(new \Core\Webhook\Contracts\ReplayWebhookDeliveryCommand(
                deliveryId: $delivery,
            ));
        } catch (\Core\Webhook\Domain\Exception\WebhookDeliveryNotFound $e) {
            return redirect()->route('admin.webhook-deliveries.dead')
                ->withErrors(['id' => 'Delivery not found.']);
        } catch (\Core\Webhook\Domain\Exception\WebhookDeliveryNotDead $e) {
            return redirect()->route('admin.webhook-deliveries.dead')
                ->withErrors(['id' => 'Delivery is not dead; no need to replay.']);
        }

        return redirect()->route('admin.webhook-deliveries.dead')
            ->with('status', 'Replay queued — new delivery id: '.substr($result->newDeliveryId, 0, 8).'…');
    }
}
