<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\ClientApplication\ClientApplicationConnection;
use App\Billing\ClientApplication\ClientApplicationConnections;
use App\Billing\ClientApplication\ClientApplicationEndpoint;
use App\Billing\ClientApplication\ClientApplicationReadFailed;
use App\Billing\ClientApplication\ClientApplicationTarget;
use App\Billing\ClientApplication\ImportTenantsFromApplication;
use App\Billing\ClientApplication\ProposeTenantFormFromApplication;
use App\Billing\ClientApplication\ResolveClientApplicationTarget;
use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Webhook\Contracts\WebhookEndpointView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0133 — la page *Application cliente* : l'endroit où un projet est
 * branché, et où tout se passe ensuite.
 *
 * L'opérateur y fait deux gestes, et rien d'autre :
 *
 *  1. **récupérer la forme** que l'application veut — la déclaration est
 *     validée par les mêmes règles qu'une déclaration reçue par l'API, puis
 *     enregistrée comme **proposition** ; l'opérateur l'accepte ou la
 *     refuse, comme toute proposition (ADR-0122 §2) ;
 *  2. **créer les clients** que l'application a déjà — l'aperçu dit ce qui
 *     sera créé, ce qui existe déjà et ce qui sera refusé ; la création
 *     emprunte **exactement** le chemin d'écriture de l'import en lot
 *     (ADR-0133 §4).
 *
 * L'adresse de l'application n'est pas saisie : elle est **déduite** du
 * point d'entrée déjà enregistré, et la console la montre pour que
 * l'opérateur puisse la corriger (ADR-0133 §2).
 */
final class BillingClientApplicationController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetTenantSchema $tenantSchemas,
        private readonly ListCustomers $customers,
        private readonly ResolveClientApplicationTarget $targets,
        private readonly ProposeTenantFormFromApplication $proposeForm,
        private readonly ImportTenantsFromApplication $pullClients,
        private readonly ClientApplicationConnections $connections,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * L'état du branchement : ce qui est fait, ce qui manque, et le bouton
     * correspondant.
     */
    public function show(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        return $this->page($projectId, $scope, null, null);
    }

    /**
     * « Récupérer la forme de l'application » — la déclaration devient une
     * proposition en attente.
     */
    public function fetchForm(OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $endpoint = $this->endpoint($projectId, $scope);

        if ($endpoint === null) {
            return back()->withErrors(['resource' => $this->reason($projectId, $scope)]);
        }

        try {
            $proposal = $this->proposeForm->propose($endpoint, $this->actor($operator));
        } catch (ClientApplicationReadFailed $exception) {
            return back()->withErrors(['resource' => $exception->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.tenant_schema.proposed',
            resource: 'tenant_schema:'.$proposal->schema->id,
            before: null,
            // ADR-0083 §4 — l'audit dit quelles parties de la forme
            // entrent en jeu ; il ne porte jamais la valeur d'un client.
            after: ['changed_fields' => ['unique_key', 'fields']],
            context: ['project_id' => $projectId, 'version' => $proposal->schema->version],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        if (! $proposal->created) {
            // ADR-0130 §3 — une proposition identique à une forme déjà
            // connue ne crée pas de version de plus.
            return back()->with('status', 'La forme en vigueur est déjà celle de l\'application : rien n\'a été changé.');
        }

        return back()->with('status', 'La forme de l\'application est arrivée : lisez-la, puis acceptez-la ou refusez-la.');
    }

    /**
     * « Créer les clients depuis l'application » — l'aperçu, d'abord :
     * combien seront créés, combien sont déjà connus, combien seront
     * refusés, et pourquoi.
     */
    public function previewClients(OperatorContext $operator, string $projectId): Response|RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $endpoint = $this->endpoint($projectId, $scope);

        if ($endpoint === null) {
            return back()->withErrors(['resource' => $this->reason($projectId, $scope)]);
        }

        try {
            $report = $this->pullClients->preview($endpoint, $scope, $this->actor($operator));
        } catch (ClientApplicationReadFailed $exception) {
            return back()->withErrors(['resource' => $exception->getMessage()]);
        }

        return $this->page($projectId, $scope, $report, 'preview');
    }

    /**
     * La création : le même chemin d'écriture que l'import en lot, et le
     * même audit.
     */
    public function importClients(OperatorContext $operator, string $projectId): Response|RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $endpoint = $this->endpoint($projectId, $scope);

        if ($endpoint === null) {
            return back()->withErrors(['resource' => $this->reason($projectId, $scope)]);
        }

        try {
            $report = $this->pullClients->import($endpoint, $scope, $this->actor($operator));
        } catch (ClientApplicationReadFailed $exception) {
            return back()->withErrors(['resource' => $exception->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customers.imported',
            resource: 'project:'.$projectId,
            before: null,
            // ADR-0083 §4 — l'audit compte les lignes ; il ne porte ni la
            // valeur d'un client, ni celle d'une ligne refusée.
            after: ['created' => $report['created'], 'already_known' => $report['alreadyKnown'], 'refused' => $report['refused']],
            context: ['project_id' => $projectId, 'lines' => $report['lines']],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return $this->page($projectId, $scope, $report, 'import');
    }

    /**
     * L'adresse appelée par le Core : le point d'entrée choisi, et
     * l'adresse corrigée quand l'opérateur la corrige.
     */
    public function saveAddress(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $target = $this->targets->resolve($projectId, $scope);

        $endpointId = $request->input('endpoint_id');
        $baseUrl = $request->input('base_url');

        if (! is_string($endpointId) || ! is_string($baseUrl)) {
            return back()->withErrors(['base_url' => 'Indiquez le point d\'entrée et l\'adresse de l\'application.']);
        }

        $chosen = null;

        foreach ($target->choices as $choice) {
            if ($choice->id === $endpointId) {
                $chosen = $choice;
            }
        }

        if ($chosen === null) {
            return back()->withErrors(['endpoint_id' => 'Ce point d\'entrée n\'appartient pas à ce projet.']);
        }

        try {
            $endpoint = ClientApplicationEndpoint::at($projectId, $chosen->id, $baseUrl);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['base_url' => $exception->getMessage()]);
        }

        $this->connections->save(new ClientApplicationConnection(
            projectId: $projectId,
            endpointId: $chosen->id,
            baseUrl: $endpoint->baseUrl,
        ));

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.client_application.address_saved',
            resource: 'project:'.$projectId,
            before: null,
            after: ['changed_fields' => ['endpoint_id', 'base_url']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return back()->with('status', 'Adresse enregistrée : le Core appellera l\'application à cette adresse.');
    }

    /**
     * La page, et tout ce qu'elle montre.
     *
     * @param array{lines:int, created:int, alreadyKnown:int, refused:int, refusals:list<array{line:int, reference:string, reason:string}>, known:list<array{line:int, reference:string}>, interrupted:string|null}|null $report
     */
    private function page(string $projectId, AccessScope $scope, ?array $report, ?string $reportKind): Response
    {
        $target = $this->targets->resolve($projectId, $scope);

        return response()->view('admin.billing.customers.application', [
            'title' => 'Application cliente',
            'projectId' => $projectId,
            'target' => $target,
            'endpoint' => $this->endpointView($target),
            'form' => $this->tenantSchemas->accepted($projectId, $scope),
            'proposal' => $this->tenantSchemas->pending($projectId, $scope),
            'knownCustomers' => count($this->customers->list(
                new ListCustomersCommand($projectId, null, 100),
                $scope,
            )->items),
            'report' => $report,
            'reportKind' => $reportKind,
        ]);
    }

    /**
     * Le point d'entrée que le Core va appeler, quand il en connaît un.
     */
    private function endpoint(string $projectId, AccessScope $scope): ?ClientApplicationEndpoint
    {
        return $this->targets->resolve($projectId, $scope)->endpoint();
    }

    /**
     * Pourquoi le Core ne peut pas appeler l'application, quand il ne le
     * peut pas.
     */
    private function reason(string $projectId, AccessScope $scope): string
    {
        return $this->targets->resolve($projectId, $scope)->reason
            ?? 'L\'application n\'est pas branchée au Core.';
    }

    /**
     * Le point d'entrée retenu, en lecture — pour l'afficher.
     */
    private function endpointView(ClientApplicationTarget $target): ?WebhookEndpointView
    {
        if ($target->endpointId === null) {
            return null;
        }

        foreach ($target->choices as $choice) {
            if ($choice->id === $target->endpointId) {
                return $choice;
            }
        }

        return null;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }
}
