<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\ProjectView;
use Core\Support\Clock;
use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Core\Webhook\Contracts\ActivateWebhookEndpoint;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\RevokePreviousWebhookSecret;
use Core\Webhook\Contracts\RevokePreviousWebhookSecretCommand;
use Core\Webhook\Contracts\RotateWebhookSecret;
use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
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
    use ResolvesScopedProject;

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
        'subscriptions' => ['label' => 'Abonnements', 'events' => ['subscription.created', 'subscription.trial_will_end', 'subscription.activated', 'subscription.renewed', 'subscription.renewal_due', 'subscription.plan_changed', 'subscription.past_due', 'subscription.paused', 'subscription.resumed', 'subscription.canceled', 'subscription.expired', 'customer.suspended', 'customer.reactivated']],
        'invoices' => ['label' => 'Factures', 'events' => ['invoice.issued', 'invoice.paid', 'invoice.payment_failed', 'invoice.voided']],
        'usage' => ['label' => 'Quotas', 'events' => ['usage.threshold_reached', 'usage.limit_reached']],
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
        private readonly RotateWebhookSecret $rotateWebhookSecret,
        private readonly RevokePreviousWebhookSecret $revokePreviousWebhookSecret,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        $project = $this->authorizedProject($operator, $projectId);

        return $this->render($projectId, $operator, $project, null, null);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        // ADR-0069 §2 — the endpoint is registered on a project, so the
        // project must be inside the account's scope: without this, an
        // operator could attach a receiver to another tenant's project.
        $project = $this->authorizedProject($operator, $projectId);

        /** @var array{url: string, categories?: list<string>, all?: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
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

        try {
            $view = $this->registerWebhookEndpoint->register(new RegisterWebhookEndpointCommand(
                projectId: $projectId,
                url: $data['url'],
                events: $events,
            ));
        } catch (TooManyWebhookEndpoints $refusal) {
            // Audit 2026-10-29 #17 / G-12 — the ceiling of active endpoints
            // per project: the operator stays on the form, with the reason.
            return $this->refuseTooManyEndpoints($refusal, 'url');
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.registered',
            resource: 'webhook_endpoint:'.$view->id,
            before: null,
            after: ['project_id' => $projectId, 'url' => $view->url, 'events' => $view->events],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
            organizationId: $project->organizationId,
        ));

        // The plaintext signing secret is shown exactly once, in THIS
        // response (ADR-0081 S-07), never flashed into the session store.
        return $this->render($projectId, $operator, $project, [
            'url' => $view->url,
            'secret' => $view->secret,
        ], null);
    }

    public function update(string $projectId, string $endpointId, Request $request, OperatorContext $operator): RedirectResponse
    {
        // ADR-0069 §2 — the endpoint belongs to a project: that project
        // must be inside the account's scope, otherwise the console could
        // rewrite the receiver of another tenant.
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

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
            projectId: $projectId,
            organizationId: $project->organizationId,
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

    /**
     * ADR-0082 §5 — a new signing secret, shown once; the previous one keeps
     * signing (`X-Signature-Previous`) for the chosen overlap.
     */
    public function rotateSecret(string $projectId, string $endpointId, Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

        /** @var array{overlap_hours: int|string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'overlap_hours' => ['required', 'integer', 'between:'.RotateWebhookSecretCommand::MIN_OVERLAP_HOURS.','.RotateWebhookSecretCommand::MAX_OVERLAP_HOURS],
        ]);

        try {
            $rotated = $this->rotateWebhookSecret->rotate(new RotateWebhookSecretCommand($projectId, $endpointId, (int) $data['overlap_hours']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['endpoint' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.secret_rotated',
            resource: 'webhook_endpoint:'.$endpointId,
            before: null,
            // Never a secret — only when the previous one stops signing.
            after: ['project_id' => $projectId, 'previous_secret_expires_at' => $rotated->previousSecretExpiresAt->format(DATE_ATOM)],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
            organizationId: $project->organizationId,
        ));

        // Shown exactly once, in THIS response, never flashed (S-07).
        return $this->render($projectId, $operator, $project, null, [
            'secret' => $rotated->secret,
            'previousSecretExpiresAt' => $rotated->previousSecretExpiresAt->format('d/m/Y H:i'),
        ]);
    }

    /** ADR-0082 §5 — end the overlap now, with a reason (audited). */
    public function revokePreviousSecret(string $projectId, string $endpointId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

        /** @var array{reason: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $revoked = $this->revokePreviousWebhookSecret->revoke(new RevokePreviousWebhookSecretCommand($projectId, $endpointId));
        } catch (RuntimeException $e) {
            return back()->withErrors(['endpoint' => $e->getMessage()]);
        }

        if (! $revoked) {
            return back()->withErrors(['endpoint' => 'Il n\'y a plus d\'ancien secret à révoquer pour cet endpoint.']);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.previous_secret_revoked',
            resource: 'webhook_endpoint:'.$endpointId,
            before: null,
            after: ['project_id' => $projectId],
            context: ['reason' => $data['reason']],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
            organizationId: $project->organizationId,
        ));

        return redirect()->route('admin.projects.webhook-endpoints.create', ['projectId' => $projectId])
            ->with('status', 'Ancien secret révoqué : seules les signatures du nouveau secret sont envoyées désormais.');
    }

    /**
     * Les secrets en clair ne voyagent que par les arguments : rendus dans
     * CETTE réponse, jamais lus dans le store de session.
     *
     * @param array{url: string, secret: string}|null $created
     * @param array{secret: string, previousSecretExpiresAt: string}|null $rotated
     */
    private function render(string $projectId, OperatorContext $operator, ProjectView $project, ?array $created, ?array $rotated): Response
    {
        $endpoints = $this->listWebhookEndpoints->list(new ListWebhookEndpointsCommand($projectId), $operator->scope);

        return response()->view('admin.webhook-endpoints.create', [
            'title' => 'Endpoints webhook · '.$project->name,
            'project' => $project,
            'endpoints' => $endpoints,
            'categories' => self::CATEGORIES,
            'created' => $created,
            'rotated' => $rotated,
            'overlap' => ['min' => RotateWebhookSecretCommand::MIN_OVERLAP_HOURS, 'max' => RotateWebhookSecretCommand::MAX_OVERLAP_HOURS, 'default' => RotateWebhookSecretCommand::DEFAULT_OVERLAP_HOURS],
        ]);
    }

    /**
     * Audit 2026-10-29 #17 / G-12 — the ceiling of active endpoints a
     * project may hold. The console answers a refused write the way it
     * answers any invalid input: the operator stays on the page, with the
     * reason (never a crash, never a bare 500).
     */
    private function refuseTooManyEndpoints(TooManyWebhookEndpoints $refusal, string $key): RedirectResponse
    {
        return back()->withErrors([$key => sprintf(
            'Ce projet atteint déjà son plafond de %d endpoints webhook actifs. Désactivez-en un avant d\'en enregistrer un autre.',
            $refusal->limit,
        )]);
    }

    /**
     * ADR-0069 §2 — the project must be in the account's scope (404), and
     * the account's role in its organization must carry the permission
     * (403).
     */
    private function authorizedProject(OperatorContext $operator, string $projectId): ProjectView
    {
        $this->permittedScope($operator, 'webhook-endpoints:write');

        return $this->scopedProject($this->getProject, $operator, $projectId, 'webhook-endpoints:write');
    }

    private function authorizeEndpoint(OperatorContext $operator, string $projectId, string $endpointId): ProjectView
    {
        $project = $this->authorizedProject($operator, $projectId);

        Validator::make(['endpoint_id' => $endpointId], ['endpoint_id' => 'uuid'])->validate();

        return $project;
    }

    private function setActive(string $projectId, string $endpointId, bool $active, OperatorContext $operator): RedirectResponse
    {
        // Permission, UUID shape and scope in one place — the same gate
        // the secret actions go through. ADR-0069 §2: activating or
        // deactivating a receiver of a project outside the account's
        // scope would let one tenant silence another tenant's webhook.
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

        try {
            if ($active) {
                $this->activateWebhookEndpoint->activate(new ActivateWebhookEndpointCommand($projectId, $endpointId));
            } else {
                $this->deactivateWebhookEndpoint->deactivate(new DeactivateWebhookEndpointCommand($projectId, $endpointId));
            }
        } catch (TooManyWebhookEndpoints $refusal) {
            // Audit 2026-10-29 #17 / G-12 — re-activating an endpoint adds
            // one to the project's active count: it is refused at the
            // ceiling, exactly like a registration.
            return $this->refuseTooManyEndpoints($refusal, 'endpoint');
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
            projectId: $projectId,
            organizationId: $project->organizationId,
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
