<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesProjectNames;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\GetWebhookDeliveryDetails;
use Core\Webhook\Contracts\ListWebhookDeliveries;
use Core\Webhook\Contracts\ListWebhookDeliveriesCommand;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Exception\WebhookEndpointInactive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook delivery admin: the full execution history — every status, not
 * only the dead queue — plus the replay action for dead rows. Review
 * finding: an operator diagnosing a problem could only ever see the
 * failures, never confirm whether a given event was attempted at all or
 * delivered successfully ("on doit tout voir pas seulement ceux qui sont
 * passés … pour diagnostiquer un problème on peut voir ce qui a vraiment
 * été exécuté").
 *
 * The replay button on each dead row uses Alpine.js for a confirmation
 * dialog (see webhook-deliveries/dead.blade.php) — the POST to
 * `/webhook-deliveries/{id}/replay` is what this controller serves.
 */
final class WebhookDeliveriesController
{
    use ResolvesProjectNames;
    use ResolvesScopedProject;

    public function __construct(
        private readonly ListWebhookDeliveries $listDeliveries,
        private readonly ReplayWebhookDelivery $replay,
        private readonly QueueWebhookDelivery $queue,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly GetProject $getProject,
        private readonly ListWebhookEndpoints $listEndpoints,
        private readonly GetWebhookDeliveryDetails $details,
    ) {}

    /**
     * ADR-0062 — one delivery with every attempt: the exact signed request
     * sent (signature masked), the receiver's answer, the duration and what
     * the Core decided next.
     *
     * ADR-0069 §2 / S-04 — a delivery of a project outside the account's
     * scope is answered like a missing one ("livraison introuvable"),
     * never with a 403: the reply must not confirm that the resource
     * exists.
     */
    public function show(string $delivery, OperatorContext $operator): Response|RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'webhooks:read');

        Validator::make(['delivery' => $delivery], ['delivery' => 'uuid'])->validate();

        $view = $this->details->forDelivery($delivery, $scope);
        if ($view === null) {
            return redirect()->route('admin.webhook-deliveries.dead.web')->withErrors(['id' => 'Livraison introuvable.']);
        }

        return response()->view('admin.webhook-deliveries.show', [
            'title' => 'Livraison '.substr($view->id, 0, 8).'…',
            'delivery' => $view,
            'projectNames' => $this->resolveProjectNames($this->getProject, [$view->projectId], $scope),
            'canReplay' => $operator->scopeFor('webhooks:replay')->allowsProject($view->projectId),
        ]);
    }

    public function index(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'webhooks:read');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $projectId = (string) $request->query('project_id', '');
        $status = (string) $request->query('status', '');

        if ($projectId !== '') {
            Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();
        }

        // No status filter by default — the whole execution history,
        // not just the dead queue (see class docblock).
        $pageResult = $this->listDeliveries->list(new ListWebhookDeliveriesCommand(
            status: $status === '' ? null : $status,
            projectId: $projectId === '' ? null : $projectId,
            page: $page,
            perPage: $perPage,
        ), $scope);

        $projectIds = array_map(
            static fn ($delivery): string => $delivery->projectId,
            $pageResult->items,
        );

        return response()->view('admin.webhook-deliveries.dead', [
            'title' => 'Livraisons de webhooks',
            'deliveries' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['project_id' => $projectId, 'status' => $status],
            'projectNames' => $this->resolveProjectNames($this->getProject, $projectIds, $scope),
            'endpointUrls' => $this->resolveEndpointUrls($projectIds, $scope),
        ]);
    }

    public function replay(string $delivery, OperatorContext $operator): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'webhooks:replay');

        // Validate UUID shape before delegating — otherwise the contract
        // throws a 404 which is rendered as problem+json (correct for API
        // clients, confusing for the web UI which would rather see a
        // friendlier redirect).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $delivery) !== 1) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Identifiant de livraison invalide.']);
        }

        // ADR-0069 §2 / S-04 — the delivery is resolved *inside the
        // operator's scope* before it is replayed: a delivery of a project
        // the account may not see is "not found", never "forbidden" — the
        // answer must not confirm that the resource exists, and replaying
        // it would re-send another tenant's payload to its receiver.
        $detail = $this->details->forDelivery($delivery, $scope);
        if ($detail === null) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Livraison introuvable.']);
        }

        try {
            $result = $this->replay->replay(new ReplayWebhookDeliveryCommand(
                deliveryId: $delivery,
            ));
        } catch (WebhookDeliveryNotFound) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => 'Livraison introuvable.']);
        } catch (WebhookDeliveryNotDead) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => "Cette livraison n'est pas en échec ; aucun rejeu nécessaire."]);
        } catch (WebhookEndpointInactive) {
            return redirect()->route('admin.webhook-deliveries.dead.web')
                ->withErrors(['id' => "L'endpoint de cette livraison est désactivé : réactivez-le avant de rejouer."]);
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
            projectId: $detail->projectId,
        ));

        return redirect()->route('admin.webhook-deliveries.dead.web')
            ->with('status', 'Rejeu mis en file — nouvel id de livraison : '.substr($result->newDeliveryId, 0, 8).'…');
    }

    /**
     * The endpoint URL — not carried by `DeadWebhookDeliveryView`, only
     * its id — is the single most useful piece of context on this page
     * (review finding: "les webhooks tu n'as même pas de détails"; a
     * truncated endpoint UUID tells an operator nothing about which
     * receiver is failing). `ListWebhookEndpoints` is project-scoped, not
     * a global lookup-by-id, so this fetches each unique project's
     * endpoint list once and flattens the results into one id => url map.
     *
     * ADR-0069 §2 — the operator's scope is passed down: a project the
     * account may not see yields no endpoint, hence no URL.
     *
     * @param list<string> $projectIds
     * @return array<string, string> endpointId => url
     */
    private function resolveEndpointUrls(array $projectIds, AccessScope $scope): array
    {
        $urls = [];
        foreach (array_unique($projectIds) as $projectId) {
            foreach ($this->listEndpoints->list(new ListWebhookEndpointsCommand($projectId), $scope) as $endpoint) {
                $urls[$endpoint->id] = $endpoint->url;
            }
        }

        return $urls;
    }
}
