<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Billing\Contracts\ReactivateCustomer;
use Core\Billing\Contracts\ReactivateCustomerCommand;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customers of a project (ADR-0070) — the console mirror of the
 * public API.
 *
 * A customer is **declared by the project**, through a reference the
 * project knows it by. The console is where an operator corrects that
 * declaration: it creates the customer, updates it, suspends it or
 * erases its identity (ADR-0083).
 *
 * Two rules run through every action:
 *
 *  1. **The scope.** The page reads through the account's scope narrowed
 *     to `customers:read`; a project the account may not see is a 404,
 *     never a 403 (ADR-0069 §2, S-04).
 *  2. **The audit.** The console's acts are the operator's acts: they
 *     are audited here, because the `billing` module may not depend on
 *     the `audit` module (ADR-0092 §3). The audit entry records **which
 *     fields changed**, never their values (ADR-0083 §4) — the display
 *     name and the contact are personal data.
 */
final class BillingCustomersController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListCustomers $listCustomers,
        private readonly GetCustomer $getCustomer,
        private readonly UpsertCustomer $upsertCustomer,
        private readonly SuspendCustomer $suspendCustomer,
        private readonly ReactivateCustomer $reactivateCustomer,
        private readonly EraseCustomer $eraseCustomer,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        $page = $this->listCustomers->list(new ListCustomersCommand(
            projectId: $projectId,
            cursor: $this->cursor($request),
            limit: $this->limit($request),
            search: $this->search($request),
        ), $scope);

        return response()->view('admin.billing.customers.index', [
            'title' => 'Clients',
            'projectId' => $projectId,
            'customers' => $page->items,
            'nextCursor' => $page->nextCursor,
            'search' => $this->search($request) ?? '',
        ]);
    }

    public function create(OperatorContext $operator, string $projectId): Response
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        return response()->view('admin.billing.customers.form', [
            'title' => 'Nouveau client',
            'projectId' => $projectId,
            'customer' => null,
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $data = $this->validateCustomer($request);

        try {
            $view = $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                projectId: $projectId,
                externalRef: $data['external_ref'],
                displayName: $data['display_name'],
                email: $data['email'],
                phone: $data['phone'],
                locale: $data['locale'],
                metadata: [],
                expectedVersion: null,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerEmailAlreadyTaken $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customer.created',
            resource: 'customer:'.$view->id,
            before: null,
            after: ['changed_fields' => ['external_ref', 'display_name', 'status']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.index', $projectId)
            ->with('status', sprintf('Client %s créé.', $view->externalRef));
    }

    public function edit(OperatorContext $operator, string $projectId, string $externalRef): Response
    {
        $scope = $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        $customer = $this->getCustomer->get(
            new GetCustomerCommand($projectId, $externalRef),
            $scope,
        );

        if ($customer === null) {
            abort(404, 'Client introuvable.');
        }

        return response()->view('admin.billing.customers.form', [
            'title' => sprintf('Client %s', $customer->externalRef),
            'projectId' => $projectId,
            'customer' => $customer,
        ]);
    }

    public function update(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $data = $this->validateCustomer($request);

        $version = $request->integer('version');
        $expectedVersion = $version > 0 ? $version : null;

        // The console's form **updates** a customer; it never creates
        // one (the public API's upsert does both — ADR-0070 §2). A
        // reference that carries no customer of this project is a
        // reference that does not exist here: answered like a missing
        // one, never by creating a row the operator never asked for.
        $existing = $this->getCustomer->get(
            new GetCustomerCommand($projectId, $externalRef),
            $this->permittedScope($operator, 'customers:read'),
        );

        if ($existing === null) {
            return $this->refused(CustomerNotFound::becauseExternalRef($externalRef));
        }

        try {
            $view = $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                projectId: $projectId,
                externalRef: $externalRef,
                displayName: $data['display_name'],
                // ADR-0083 §3 — the console never shows the contact in
                // clear, so an empty field means "keep what is stored",
                // not "erase it".
                email: $data['email'] ?? $existing->email,
                phone: $data['phone'] ?? $existing->phone,
                locale: $data['locale'],
                metadata: [],
                expectedVersion: $expectedVersion,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerVersionConflict|CustomerEmailAlreadyTaken $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customer.updated',
            resource: 'customer:'.$view->id,
            before: ['changed_fields' => ['external_ref', 'display_name', 'status']],
            after: ['changed_fields' => ['external_ref', 'display_name', 'status']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.index', $projectId)
            ->with('status', sprintf('Client %s mis à jour.', $view->externalRef));
    }

    public function suspend(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        try {
            $view = $this->suspendCustomer->suspend(new SuspendCustomerCommand(
                projectId: $projectId,
                externalRef: $externalRef,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerNotFound|InvalidCustomerTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customer.suspended',
            resource: 'customer:'.$view->id,
            before: ['status' => 'active'],
            after: ['status' => $view->status],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.index', $projectId)
            ->with('status', sprintf('Client %s suspendu.', $view->externalRef));
    }

    public function reactivate(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        try {
            $view = $this->reactivateCustomer->reactivate(new ReactivateCustomerCommand(
                projectId: $projectId,
                externalRef: $externalRef,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerNotFound|InvalidCustomerTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customer.reactivated',
            resource: 'customer:'.$view->id,
            before: ['status' => 'suspended'],
            after: ['status' => $view->status],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.index', $projectId)
            ->with('status', sprintf('Client %s réactivé.', $view->externalRef));
    }

    public function erase(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        try {
            $view = $this->eraseCustomer->erase(new EraseCustomerCommand(
                projectId: $projectId,
                externalRef: $externalRef,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerNotFound|InvalidCustomerTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customer.erased',
            resource: 'customer:'.$view->id,
            before: ['status' => 'active'],
            after: ['status' => $view->status],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.index', $projectId)
            ->with('status', sprintf('Client %s effacé.', $view->externalRef));
    }

    /**
     * @return array{external_ref: string, display_name: string, email: ?string, phone: ?string, locale: string}
     */
    private function validateCustomer(Request $request): array
    {
        /** @var array{external_ref: string, display_name: string, email?: string|null, phone?: string|null, locale?: string|null} $data */
        $data = Validator::make($request->all(), [
            'external_ref' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            // ADR-0070 §Sécurité — no control character in a name that
            // is displayed, logged and compared.
            'display_name' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
        ])->validate();

        return [
            'external_ref' => $data['external_ref'],
            'display_name' => $data['display_name'],
            'email' => isset($data['email']) && $data['email'] !== '' ? $data['email'] : null,
            'phone' => isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null,
            'locale' => isset($data['locale']) && $data['locale'] !== '' ? $data['locale'] : 'fr',
        ];
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function limit(Request $request): int
    {
        return min(100, max(1, (int) $request->query('limit', 50)));
    }

    private function search(Request $request): ?string
    {
        $search = $request->query('search');

        return is_string($search) && $search !== '' ? $search : null;
    }
}
