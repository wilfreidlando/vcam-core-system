<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Core\Support\Environment;
use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Core\Webhook\Contracts\ActivateWebhookEndpoint;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\UpdateWebhookEndpoint;
use Core\Webhook\Contracts\UpdateWebhookEndpointCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console web mirror of `Core\Webhook\Presentation\Http\Controllers\RegisterWebhookEndpointController`
 * / `ListWebhookEndpointsController` (the JSON endpoints, ADR-0040 §6) —
 * same ports, same permission gate. `webhook` cannot depend on `audit`
 * (module graph), same reasoning as `ProviderApplicationsController`, so
 * this controller records the `webhook_endpoint.registered` entry itself
 * after calling the port.
 *
 * Review finding: the JSON self-service endpoint existed but the console
 * had no form at all for it — an operator wanting a Project to actually
 * receive its outbox-relayed events (payment.status_changed,
 * wallet.credited, …) had no way to register a receiving URL short of a
 * raw curl call to the JSON API.
 *
 * Second review finding, user-directed: the form used to ask the operator
 * to type raw event-type strings (`payment.status_changed`) by hand — a
 * leaked implementation detail nobody outside this codebase should need to
 * know. `CATEGORIES` is the one place that maps a product-level concept
 * ("Paiements") to the actual event-type strings behind it; the operator
 * picks a domain, the code owns what that means. The JSON API
 * (`POST /v1/webhook-endpoints`) is untouched and still accepts an
 * arbitrary `events` list for a caller that genuinely needs finer control
 * — this console form is deliberately the simpler, common-case path.
 *
 * The signing secret is a write-once value from the operator's point of
 * view too — this form never displays a previously-registered secret
 * back (no read port for it; `WebhookEndpointRepository` never exposes
 * one), matching `ProviderApplicationsController`'s public/private key
 * pattern.
 */
final class WebhookEndpointsController
{
    /**
     * One product-level category per meaningful integration purpose — the
     * same grouping `/admin/webhook-events` documents. `'all'` is
     * special-cased in `store()`: it always resolves to `['*']`, never to
     * the union of every other category's events (keeping it correct even
     * if a future event type doesn't fit any existing category yet).
     *
     * @var array<string, array{label: string, events: list<string>}>
     */
    private const CATEGORIES = [
        'payments' => ['label' => 'Paiements', 'events' => ['payment.status_changed']],
        'wallets' => ['label' => 'Wallets', 'events' => ['wallet.credited', 'wallet.debited']],
        'notifications' => ['label' => 'Notifications', 'events' => ['notification.delivered', 'notification.read', 'notification.failed']],
    ];

    public function __construct(
        private readonly RegisterWebhookEndpoint $registerWebhookEndpoint,
        private readonly ListWebhookEndpoints $listWebhookEndpoints,
        private readonly UpdateWebhookEndpoint $updateWebhookEndpoint,
        private readonly ActivateWebhookEndpoint $activateWebhookEndpoint,
        private readonly DeactivateWebhookEndpoint $deactivateWebhookEndpoint,
        private readonly GetProject $getProject,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('webhook-endpoints:write')) {
            abort(403, 'La permission webhook-endpoints:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        $endpoints = $this->listWebhookEndpoints->list(new ListWebhookEndpointsCommand($projectId));

        return response()->view('admin.webhook-endpoints.create', [
            'title' => 'Endpoints webhook · '.$project->name,
            'project' => $project,
            'endpoints' => $endpoints,
            'categories' => self::CATEGORIES,
            'created' => session('admin.webhook-endpoints.created'),
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('webhook-endpoints:write')) {
            abort(403, 'La permission webhook-endpoints:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        /** @var array{url: string, environment?: string, categories?: list<string>, all?: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            // ADR-0060 — an endpoint receives the events of ONE environment.
            'environment' => ['nullable', 'in:live,sandbox'],
            // `SafeOutboundUrlRule` — security review finding: this form
            // accepted any http(s) URL, including internal/private
            // targets (SSRF), unlike the JSON API's equivalent
            // (`RegisterWebhookEndpointRequest`), which already carried
            // this rule. A registered endpoint is retried and
            // DLQ-replayed, so this was a persistent hole, not a
            // one-shot one.
            'url' => ['required', 'url', 'starts_with:http://,https://', 'max:2048', new SafeOutboundUrlRule],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'all' => ['nullable', 'string'],
        ]);

        $events = $this->resolveEvents($data['all'] ?? null, $data['categories'] ?? []);
        if ($events === []) {
            return back()->withInput()->withErrors(['categories' => 'Choisissez au moins une catégorie, ou « Tout recevoir ».']);
        }

        $view = $this->registerWebhookEndpoint->register(new RegisterWebhookEndpointCommand(
            projectId: $projectId,
            url: $data['url'],
            events: $events,
            environment: Environment::from($data['environment'] ?? 'live'),
        ));

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.registered',
            resource: 'webhook_endpoint:'.$view->id,
            before: null,
            after: ['project_id' => $projectId, 'url' => $view->url, 'events' => $view->events, 'environment' => $data['environment'] ?? 'live'],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        // The plaintext signing secret is shown exactly once, on this
        // redirect — same one-shot-secret pattern as an API key or a
        // freshly-registered provider application's keys.
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash('admin.webhook-endpoints.created', [
            'url' => $view->url,
            'secret' => $view->secret,
        ]);

        return redirect()->route('admin.projects.webhook-endpoints.create', ['projectId' => $projectId]);
    }

    public function update(string $projectId, string $endpointId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('webhook-endpoints:write')) {
            abort(403, 'La permission webhook-endpoints:write est requise.');
        }

        Validator::make(
            ['project_id' => $projectId, 'endpoint_id' => $endpointId],
            ['project_id' => 'uuid', 'endpoint_id' => 'uuid'],
        )->validate();

        /** @var array{url: string, categories?: list<string>, all?: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'url' => ['required', 'url', 'starts_with:http://,https://', 'max:2048', new SafeOutboundUrlRule],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'all' => ['nullable', 'string'],
        ]);

        $events = $this->resolveEvents($data['all'] ?? null, $data['categories'] ?? []);
        if ($events === []) {
            return back()->withInput()->withErrors(['categories' => 'Choisissez au moins une catégorie, ou « Tout recevoir ».']);
        }

        try {
            $view = $this->updateWebhookEndpoint->update(new UpdateWebhookEndpointCommand(
                projectId: $projectId,
                endpointId: $endpointId,
                url: $data['url'],
                events: $events,
            ));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['url' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.updated',
            resource: 'webhook_endpoint:'.$view->id,
            before: null,
            after: ['project_id' => $projectId, 'url' => $view->url, 'events' => $view->events],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.projects.webhook-endpoints.create', ['projectId' => $projectId])
            ->with('status', 'Endpoint mis à jour.');
    }

    public function activate(string $projectId, string $endpointId, OperatorContext $operator): RedirectResponse
    {
        return $this->setActive($projectId, $endpointId, true, $operator);
    }

    public function deactivate(string $projectId, string $endpointId, OperatorContext $operator): RedirectResponse
    {
        return $this->setActive($projectId, $endpointId, false, $operator);
    }

    private function setActive(string $projectId, string $endpointId, bool $active, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('webhook-endpoints:write')) {
            abort(403, 'La permission webhook-endpoints:write est requise.');
        }

        Validator::make(
            ['project_id' => $projectId, 'endpoint_id' => $endpointId],
            ['project_id' => 'uuid', 'endpoint_id' => 'uuid'],
        )->validate();

        try {
            if ($active) {
                $this->activateWebhookEndpoint->activate(new ActivateWebhookEndpointCommand($projectId, $endpointId));
            } else {
                $this->deactivateWebhookEndpoint->deactivate(new DeactivateWebhookEndpointCommand($projectId, $endpointId));
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['endpoint' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: $active ? 'webhook_endpoint.activated' : 'webhook_endpoint.deactivated',
            resource: 'webhook_endpoint:'.$endpointId,
            before: null,
            after: ['project_id' => $projectId, 'active' => $active],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.projects.webhook-endpoints.create', ['projectId' => $projectId])
            ->with('status', $active ? 'Endpoint réactivé.' : 'Endpoint désactivé.');
    }

    /**
     * The single place `store()` and `update()` both go through to turn
     * a form submission (checked categories, or the "tout recevoir"
     * checkbox) into the real event-type-string list — `'all'` wins
     * outright over any categories also ticked, rather than silently
     * ANDing/ORing with them.
     *
     * @param list<string> $categories
     * @return list<string>
     */
    private function resolveEvents(?string $all, array $categories): array
    {
        if ($all === '1') {
            return ['*'];
        }

        $events = [];
        foreach ($categories as $category) {
            $events = [...$events, ...self::CATEGORIES[$category]['events']];
        }

        return $events;
    }
}
