<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\ProjectView;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Core\Webhook\Contracts\ActivateWebhookEndpoint;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Contracts\ListWebhookDeliveries;
use Core\Webhook\Contracts\ListWebhookDeliveriesCommand;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\RevokePreviousWebhookSecret;
use Core\Webhook\Contracts\RevokePreviousWebhookSecretCommand;
use Core\Webhook\Contracts\RotateWebhookSecret;
use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Core\Webhook\Contracts\TestWebhookEndpoint;
use Core\Webhook\Contracts\TestWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Core\Webhook\Contracts\UpdateWebhookEndpoint;
use Core\Webhook\Contracts\UpdateWebhookEndpointCommand;
use Core\Webhook\Contracts\WebhookEventTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console page where an operator registers the webhook endpoint of a
 * **client application** (ADR-0110 §5, ADR-0107 §6).
 *
 * The registration is an **operator gesture**, not an API call the client
 * application makes: the application never holds a webhook secret of its
 * own — the operator hands it over, out of band, like a password typed on
 * the phone (ADR-0110 §5, décision 5). The console is therefore the place
 * where a receiving URL, its event subscription and its signing secret
 * come into existence.
 *
 * The same ports as the JSON API (`POST /v1/webhook-endpoints`,
 * ADR-0040 §6) — the console calls the module's `Contracts` directly, it
 * never calls itself over HTTP. `webhook` cannot depend on `audit` (module
 * graph), so this controller records the audit entries itself after
 * calling the port, like `ProviderApplicationsController` does.
 *
 * Three things this page owes the operator, and how they are kept:
 *
 *  - **the secret is shown once** (ADR-0081 S-07): it is rendered in the
 *    response that creates it, never flashed into the session store, and
 *    never rendered again — there is no read port for it;
 *  - **the rotation keeps the receiver alive** (ADR-0082 §5): the new
 *    secret is shown once, and the previous one keeps signing until the
 *    overlap ends;
 *  - **the URL is really called** (ADR-0063): the operator can send one
 *    signed test event, now, and read the receiver's answer.
 */
final class WebhookEndpointsController
{
    use ResolvesScopedProject;

    /**
     * The prefixes whose events the registration form pre-selects: the
     * commerce of a project — what a client application needs to know
     * about its own tenants (ADR-0107 §1 and §3, ADR-0110 §5).
     *
     * The operator may of course tick anything else: the form offers the
     * whole catalogue, this constant only says what is ticked by default.
     *
     * @var list<string>
     */
    private const COMMERCE_PREFIXES = ['customer', 'subscription', 'invoice', 'usage'];

    /**
     * How many recent deliveries the page reads to tell when each
     * endpoint was last called.
     *
     * The history is read once, through the module's port, and the page
     * keeps only one line per endpoint: a project that receives
     * thousands of events a day does not make this page heavy. An
     * endpoint whose last delivery is older than this window simply
     * shows no date — the column says « jamais ».
     */
    private const DELIVERY_WINDOW = 100;

    /**
     * The French label of each prefix of an event type — the group title
     * in the form.
     *
     * @var array<string, string>
     */
    private const PREFIX_LABELS = [
        'customer' => 'Clients',
        'subscription' => 'Abonnements',
        'invoice' => 'Factures',
        'usage' => 'Quotas et usage',
        'payment' => 'Paiements',
        'wallet' => 'Portefeuilles',
        'notification' => 'Notifications',
        'plan' => 'Offres',
    ];

    public function __construct(
        private readonly RegisterWebhookEndpoint $registerWebhookEndpoint,
        private readonly ListWebhookEndpoints $listWebhookEndpoints,
        private readonly ListWebhookDeliveries $listWebhookDeliveries,
        private readonly UpdateWebhookEndpoint $updateWebhookEndpoint,
        private readonly ActivateWebhookEndpoint $activateWebhookEndpoint,
        private readonly DeactivateWebhookEndpoint $deactivateWebhookEndpoint,
        private readonly GetProject $getProject,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly RotateWebhookSecret $rotateWebhookSecret,
        private readonly RevokePreviousWebhookSecret $revokePreviousWebhookSecret,
        private readonly TestWebhookEndpoint $testWebhookEndpoint,
    ) {}

    /**
     * The page of one project: its endpoints, and the form that registers
     * a new one.
     */
    public function index(string $projectId, OperatorContext $operator): Response
    {
        $project = $this->authorizedProject($operator, $projectId);

        return $this->render($projectId, $operator, $project, null, null, null);
    }

    /**
     * The historical URL of the same page (`…/webhook-endpoints/create`).
     *
     * It stays: the links already handed out (and the bookmarks) keep
     * working, and both URLs render the same page — there is no second
     * page to maintain.
     */
    public function create(string $projectId, OperatorContext $operator): Response
    {
        return $this->index($projectId, $operator);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        // ADR-0069 §2 — the endpoint is registered on a project, so the
        // project must be inside the account's scope: without this, an
        // operator could attach a receiver to another tenant's project.
        $project = $this->authorizedProject($operator, $projectId);

        /** @var array{url: string, events?: list<string>|null, all?: string|null} $data */
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
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', WebhookEventTypes::ALL)],
            'all' => ['nullable', 'string', 'in:1'],
        ]);

        $events = $this->resolveEvents($data['all'] ?? null, $data['events'] ?? []);
        if ($events === []) {
            return back()->withInput()->withErrors(['events' => 'Choisissez au moins un événement, ou « Tout recevoir ».']);
        }

        // The same URL twice would deliver every event twice to the same
        // receiver: the operator is told, and stays on the form.
        $duplicate = $this->duplicateOf($projectId, $data['url'], $this->scopeOf($operator));
        if ($duplicate !== null) {
            return back()->withInput()->withErrors(['url' => $duplicate]);
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
        ], null, null);
    }

    public function update(string $projectId, string $endpointId, Request $request, OperatorContext $operator): RedirectResponse
    {
        // ADR-0069 §2 — the endpoint belongs to a project: that project
        // must be inside the account's scope, otherwise the console could
        // rewrite the receiver of another tenant.
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

        /** @var array{url: string, events?: list<string>|null, all?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'url' => ['required', 'url', 'starts_with:http://,https://', 'max:2048', new SafeOutboundUrlRule],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', WebhookEventTypes::ALL)],
            'all' => ['nullable', 'string', 'in:1'],
        ]);

        $events = $this->resolveEvents($data['all'] ?? null, $data['events'] ?? []);
        if ($events === []) {
            return back()->withInput()->withErrors(['events' => 'Choisissez au moins un événement, ou « Tout recevoir ».']);
        }

        $duplicate = $this->duplicateOf($projectId, $data['url'], $this->scopeOf($operator), $endpointId);
        if ($duplicate !== null) {
            return back()->withInput()->withErrors(['url' => $duplicate]);
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

        return redirect()->route('admin.projects.webhook-endpoints.index', ['projectId' => $projectId])
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
        ], null);
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

        return redirect()->route('admin.projects.webhook-endpoints.index', ['projectId' => $projectId])
            ->with('status', 'Ancien secret révoqué : seules les signatures du nouveau secret sont envoyées désormais.');
    }

    /**
     * ADR-0063 — one signed `webhook.test` event, sent to the endpoint
     * right now, and the receiver's answer shown to the operator.
     *
     * It is what tells a wrong URL from a wrong secret before the first
     * real event exists. Synchronous, never retried, never persisted as a
     * delivery — the same contract as `POST /v1/webhook-endpoints/{id}/test`.
     */
    public function test(string $projectId, string $endpointId, OperatorContext $operator): Response|RedirectResponse
    {
        $project = $this->authorizeEndpoint($operator, $projectId, $endpointId);

        try {
            $result = $this->testWebhookEndpoint->test(new TestWebhookEndpointCommand($projectId, $endpointId));
        } catch (RuntimeException $e) {
            return back()->withErrors(['endpoint' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'webhook_endpoint.tested',
            resource: 'webhook_endpoint:'.$endpointId,
            before: null,
            after: ['project_id' => $projectId, 'delivered' => $result->delivered, 'response_status' => $result->responseStatus],
            // The receiver's answer is diagnostic data shown to the
            // operator — it is the receiver's own content, and the audit
            // trail keeps no secret of anybody (ADR-0081).
            context: ['event_id' => $result->eventId, 'duration_ms' => $result->durationMs],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
            organizationId: $project->organizationId,
        ));

        return $this->render($projectId, $operator, $project, null, null, [
            'endpointId' => $endpointId,
            'delivered' => $result->delivered,
            'responseStatus' => $result->responseStatus,
            'durationMs' => $result->durationMs,
            'error' => $result->error,
            // Bounded: the answer of the receiver is shown to the
            // operator, it is not kept anywhere.
            'responseBody' => $result->responseBody === null ? null : mb_substr($result->responseBody, 0, 500),
            'eventId' => $result->eventId,
        ]);
    }

    /**
     * One French line per event type — what the receiver is told when the
     * event fires.
     *
     * The **set** of event types is not written here: it comes from
     * `WebhookEventTypes::ALL`, the same closed list the JSON API
     * validates against (`RegisterWebhookEndpointRequest`). A type that
     * gains no line here is still offered — under its technical name —
     * so a new event of the Core is never hidden from the console.
     *
     * @return array<string, string>
     */
    private static function eventLabels(): array
    {
        return [
            'customer.created' => 'Un client a été créé — c\'est le signal qu\'un locataire existe pour l\'application.',
            'customer.updated' => 'Un client a été modifié.',
            'customer.erased' => 'Un client a été effacé (son identité a été détruite).',
            'customer.suspended' => 'Un client a été suspendu.',
            'customer.reactivated' => 'Un client suspendu a repris.',
            'subscription.created' => 'Un abonnement a été créé.',
            'subscription.activated' => 'Un abonnement est devenu actif.',
            'subscription.renewed' => 'Un abonnement a été renouvelé.',
            'subscription.trial_will_end' => 'La période d\'essai d\'un abonnement va se terminer.',
            'subscription.renewal_due' => 'Le renouvellement d\'un abonnement approche.',
            'subscription.plan_changed' => 'L\'offre d\'un abonnement a changé.',
            'subscription.past_due' => 'Un abonnement n\'a pas été réglé à temps.',
            'subscription.paused' => 'Un abonnement a été mis en pause.',
            'subscription.resumed' => 'Un abonnement en pause a repris.',
            'subscription.canceled' => 'Un abonnement a été résilié.',
            'subscription.expired' => 'Un abonnement est arrivé à échéance.',
            'invoice.created' => 'Une facture a été créée.',
            'invoice.issued' => 'Une facture a été émise.',
            'invoice.paid' => 'Une facture a été réglée.',
            'invoice.payment_failed' => 'Le règlement d\'une facture a échoué.',
            'invoice.voided' => 'Une facture a été annulée.',
            'invoice.receipt_recorded' => 'Un encaissement hors ligne a été imputé à une facture.',
            'invoice.uncollectible' => 'Une facture a été déclarée irrécouvrable.',
            'usage.threshold_reached' => 'L\'usage d\'un client a franchi un seuil.',
            'usage.limit_reached' => 'L\'usage d\'un client a atteint sa limite (quota).',
            'payment.status_changed' => 'Le statut d\'un paiement a changé (succès, échec, expiration…).',
            'wallet.credited' => 'Un portefeuille a été crédité.',
            'wallet.debited' => 'Un portefeuille a été débité.',
            'notification.delivered' => 'Une notification a été livrée.',
            'notification.read' => 'Le destinataire a lu la notification.',
            'notification.failed' => 'La notification n\'a pas pu être livrée.',
            'plan.archived' => 'Une offre a été archivée.',
            'plan.version_published' => 'Une version d\'une offre a été publiée.',
        ];
    }

    /**
     * Les secrets en clair ne voyagent que par les arguments : rendus dans
     * CETTE réponse, jamais lus dans le store de session.
     *
     * @param array{url: string, secret: string}|null $created
     * @param array{secret: string, previousSecretExpiresAt: string}|null $rotated
     * @param array{endpointId: string, delivered: bool, responseStatus: int|null, durationMs: int|null, error: string|null, responseBody: string|null, eventId: string}|null $tested
     */
    private function render(string $projectId, OperatorContext $operator, ProjectView $project, ?array $created, ?array $rotated, ?array $tested): Response
    {
        $scope = $this->scopeOf($operator);
        $endpoints = $this->listWebhookEndpoints->list(new ListWebhookEndpointsCommand($projectId), $scope);

        return response()->view('admin.webhook-endpoints.index', [
            'title' => 'Endpoints webhook · '.$project->name,
            'project' => $project,
            'endpoints' => $endpoints,
            'lastDeliveries' => $this->lastDeliveries($projectId, $scope),
            'eventGroups' => $this->eventGroups(),
            'created' => $created,
            'rotated' => $rotated,
            'tested' => $tested,
            'overlap' => ['min' => RotateWebhookSecretCommand::MIN_OVERLAP_HOURS, 'max' => RotateWebhookSecretCommand::MAX_OVERLAP_HOURS, 'default' => RotateWebhookSecretCommand::DEFAULT_OVERLAP_HOURS],
        ]);
    }

    /**
     * When each endpoint of the project was last called, and how it went.
     *
     * The history is read through the module's port — the console never
     * queries another module's table (G3c) — and only the most recent
     * delivery of each endpoint is kept.
     *
     * @return array<string, array{status: string, at: string|null}>
     */
    private function lastDeliveries(string $projectId, AccessScope $scope): array
    {
        $page = $this->listWebhookDeliveries->list(
            new ListWebhookDeliveriesCommand(projectId: $projectId, page: 1, perPage: self::DELIVERY_WINDOW),
            $scope,
        );

        $last = [];

        // The listing comes back from the most recent to the oldest: the
        // first delivery seen for an endpoint is its last one.
        foreach ($page->items as $delivery) {
            if (isset($last[$delivery->endpointId])) {
                continue;
            }

            $at = $delivery->deliveredAt ?? $delivery->lastAttemptAt;

            $last[$delivery->endpointId] = [
                'status' => $delivery->status,
                'at' => $at?->format('d/m/Y H:i'),
            ];
        }

        return $last;
    }

    /**
     * The scope the page reads through: the account's scope narrowed to the
     * permission this page is gated on (ADR-0069 §2) — exactly what the
     * permission opens, never an organization the account merely sees.
     */
    private function scopeOf(OperatorContext $operator): AccessScope
    {
        return $operator->scopeFor('webhook-endpoints:write');
    }

    /**
     * The catalogue the operator picks from: every event type the Core
     * emits, grouped by prefix, the commerce first (those are the ones
     * the form pre-selects).
     *
     * @return list<array{prefix: string, label: string, events: list<array{type: string, label: string, preselected: bool}>}>
     */
    private function eventGroups(): array
    {
        // The French line of each event type. The map is read as a plain
        // `array<string, string>`: a type the Core emits but this
        // controller does not describe yet falls back to its technical
        // name — it is offered, never dropped.
        $labels = self::eventLabels();

        $byPrefix = [];

        foreach (WebhookEventTypes::ALL as $type) {
            $prefix = strstr($type, '.', true);
            if (! is_string($prefix)) {
                $prefix = $type;
            }

            $byPrefix[$prefix][] = $type;
        }

        $groups = [];

        foreach ($this->orderedPrefixes(array_keys($byPrefix)) as $prefix) {
            $events = [];

            foreach ($byPrefix[$prefix] as $type) {
                $events[] = [
                    'type' => $type,
                    'label' => isset($labels[$type]) ? $labels[$type] : $type,
                    'preselected' => in_array($prefix, self::COMMERCE_PREFIXES, true),
                ];
            }

            $groups[] = [
                'prefix' => $prefix,
                'label' => self::PREFIX_LABELS[$prefix] ?? $prefix,
                'events' => $events,
            ];
        }

        return $groups;
    }

    /**
     * The commerce prefixes first — they are the ones the form
     * pre-selects, and the reason ADR-0110 exists — then the others, in
     * the order the Core emits them.
     *
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function orderedPrefixes(array $prefixes): array
    {
        $ordered = [];

        foreach (self::COMMERCE_PREFIXES as $prefix) {
            if (in_array($prefix, $prefixes, true)) {
                $ordered[] = $prefix;
            }
        }

        foreach ($prefixes as $prefix) {
            if (! in_array($prefix, self::COMMERCE_PREFIXES, true)) {
                $ordered[] = $prefix;
            }
        }

        return $ordered;
    }

    /**
     * The single place `store()` and `update()` both go through to turn a
     * form submission (checked events, or the "tout recevoir" checkbox)
     * into the real event-type list — `'*'` wins outright over any event
     * also ticked, rather than silently ANDing/ORing with them.
     *
     * @param list<string> $events
     * @return list<string>
     */
    private function resolveEvents(?string $all, array $events): array
    {
        if ($all === '1') {
            return ['*'];
        }

        $selected = [];

        foreach ($events as $event) {
            if (in_array($event, WebhookEventTypes::ALL, true)) {
                $selected[] = $event;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * The endpoint of this project that already receives on this URL, if
     * any — with the reason the operator must not register a second one.
     *
     * @param string|null $exceptEndpointId the endpoint being edited, which
     *                                      never conflicts with itself
     */
    private function duplicateOf(string $projectId, string $url, AccessScope $scope, ?string $exceptEndpointId = null): ?string
    {
        foreach ($this->listWebhookEndpoints->list(new ListWebhookEndpointsCommand($projectId), $scope) as $endpoint) {
            if ($endpoint->id === $exceptEndpointId) {
                continue;
            }

            if (! $this->sameUrl($endpoint->url, $url)) {
                continue;
            }

            return $endpoint->active
                ? 'Cette URL reçoit déjà les événements de ce projet. Le même événement y serait livré deux fois : modifiez l\'endpoint existant plutôt que d\'en enregistrer un second.'
                : 'Cette URL est déjà enregistrée pour ce projet, mais l\'endpoint est inactif. Réactivez-le plutôt que d\'en enregistrer un second : le même événement y serait livré deux fois.';
        }

        return null;
    }

    /**
     * Two URLs are the same receiver when they differ only by the case of
     * their scheme and host, or by a trailing slash.
     */
    private function sameUrl(string $left, string $right): bool
    {
        return $this->normalizeUrl($left) === $this->normalizeUrl($right);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
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

        return redirect()->route('admin.projects.webhook-endpoints.index', ['projectId' => $projectId])
            ->with('status', $active ? 'Endpoint réactivé.' : 'Endpoint désactivé.');
    }
}
