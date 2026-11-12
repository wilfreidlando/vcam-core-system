<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\RejectTenantSchema;
use Core\Billing\Contracts\RejectTenantSchemaCommand;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Exception\TenantSchemaNotPending;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0122 §2 — la déclaration que l'application a proposée, lue et
 * décidée par l'opérateur.
 *
 * L'application **propose**, l'opérateur **accepte ou refuse**. La page
 * montre la proposition comme une **liste lisible** — un libellé, un type,
 * une obligation, une origine, une longueur — jamais comme une zone de
 * texte à décortiquer : le Core ne connaît qu'une forme, et l'opérateur la
 * lit.
 *
 * Le geste est audité comme les autres gestes de la console : il dit
 * **quelles** parties de la forme entrent en vigueur, jamais les valeurs
 * d'un client (ADR-0083 §4).
 */
final class BillingTenantSchemaController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetTenantSchema $tenantSchemas,
        private readonly AcceptTenantSchema $acceptTenantSchema,
        private readonly RejectTenantSchema $rejectTenantSchema,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function show(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        return response()->view('admin.billing.customers.schema', [
            'title' => 'Forme du client',
            'projectId' => $projectId,
            'proposal' => $this->proposedSchema($projectId, $scope),
        ]);
    }

    public function accept(Request $request, OperatorContext $operator, string $projectId, string $schemaId): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        try {
            $view = $this->acceptTenantSchema->accept(new AcceptTenantSchemaCommand(
                projectId: $projectId,
                schemaId: $schemaId,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (TenantSchemaNotPending $exception) {
            return back()->withErrors(['resource' => 'Cette déclaration n\'attend plus de lecture : elle a déjà été décidée.']);
        } catch (RuntimeException $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.tenant_schema.accepted',
            resource: 'tenant_schema:'.$view->id,
            before: null,
            // ADR-0083 §4 — the audit says which parts of the form enter
            // into force; it never carries a value of a customer.
            after: ['changed_fields' => ['unique_key', 'fields']],
            context: ['project_id' => $projectId, 'version' => $view->version],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.schema', $projectId)
            ->with('status', 'Déclaration acceptée : la forme annoncée par l\'application est en vigueur.');
    }

    public function reject(Request $request, OperatorContext $operator, string $projectId, string $schemaId): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        try {
            $view = $this->rejectTenantSchema->reject(new RejectTenantSchemaCommand(
                projectId: $projectId,
                schemaId: $schemaId,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (TenantSchemaNotPending $exception) {
            return back()->withErrors(['resource' => 'Cette déclaration n\'attend plus de lecture : elle a déjà été décidée.']);
        } catch (RuntimeException $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.tenant_schema.rejected',
            resource: 'tenant_schema:'.$view->id,
            before: null,
            after: ['changed_fields' => ['unique_key', 'fields']],
            context: ['project_id' => $projectId, 'version' => $view->version],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.schema', $projectId)
            ->with('status', 'Déclaration refusée : la forme précédente reste en vigueur.');
    }

    /**
     * La déclaration **proposée** — celle qui attend d'être lue.
     *
     * Le port `GetTenantSchema` expose la forme en force et la proposition
     * en attente ; l'opérateur lit la seconde, et décide.
     */
    private function proposedSchema(string $projectId, AccessScope $scope): ?TenantSchemaView
    {
        return $this->tenantSchemas->pending($projectId, $scope);
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }
}
