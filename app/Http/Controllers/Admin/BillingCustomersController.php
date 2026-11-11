<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\TenantSchemaLabels;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Billing\Contracts\ReactivateCustomer;
use Core\Billing\Contracts\ReactivateCustomerCommand;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributesCommand;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
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
        private readonly GetTenantSchema $tenantSchemas,
        private readonly GetCustomerDeclaredAttributes $declaredAttributes,
        private readonly SetCustomerDeclaredAttributes $setDeclaredAttributes,
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
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        // ADR-0122 §6 — the form shows the pattern the application declared:
        // the operator knows what to type before he types it.
        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        return response()->view('admin.billing.customers.form', [
            'title' => 'Nouveau client',
            'projectId' => $projectId,
            'customer' => null,
            'schema' => $schema,
            'fields' => $this->declaredFields($schema),
            'values' => [],
            'referencePattern' => $this->referencePattern($schema),
            'referenceHint' => $this->referenceHint($schema),
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        $data = $this->validateCustomer($request);

        $refused = $this->assertReferenceIsAccepted($projectId, $data['external_ref'], $scope, $schema);

        if ($refused !== null) {
            return $refused;
        }

        $declared = $this->validateDeclared($request, $schema);
        $identity = $this->identity($data, $schema, $declared);

        try {
            $view = $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                projectId: $projectId,
                externalRef: $data['external_ref'],
                displayName: $identity['display_name'],
                email: $identity['email'],
                phone: $identity['phone'],
                locale: $identity['locale'],
                metadata: [],
                expectedVersion: null,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerEmailAlreadyTaken $exception) {
            return $this->refused($exception);
        }

        $notSaved = $this->saveDeclaredValues($projectId, $view->id, $declared, $schema, $scope, $operator);

        if ($notSaved !== null) {
            return $notSaved;
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

        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        return response()->view('admin.billing.customers.form', [
            'title' => sprintf('Client %s', $customer->externalRef),
            'projectId' => $projectId,
            'customer' => $customer,
            'schema' => $schema,
            'fields' => $this->declaredFields($schema),
            'values' => $schema === null
                ? []
                : $this->declaredAttributes->forCustomer($projectId, $customer->id, $scope),
            'referencePattern' => $this->referencePattern($schema),
            'referenceHint' => $this->referenceHint($schema),
        ]);
    }

    public function update(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        $data = $this->validateCustomer($request);

        $refused = $this->assertReferenceIsAccepted($projectId, $externalRef, $scope, $schema);

        if ($refused !== null) {
            return $refused;
        }

        $declared = $this->validateDeclared($request, $schema);
        $identity = $this->identity($data, $schema, $declared);

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
                displayName: $identity['display_name'],
                // ADR-0083 §3 — the console never shows the contact in
                // clear, so an empty field means "keep what is stored",
                // not "erase it".
                email: $identity['email'] ?? $existing->email,
                phone: $identity['phone'] ?? $existing->phone,
                locale: $identity['locale'],
                metadata: [],
                expectedVersion: $expectedVersion,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerVersionConflict|CustomerEmailAlreadyTaken $exception) {
            return $this->refused($exception);
        }

        $notSaved = $this->saveDeclaredValues($projectId, $view->id, $declared, $schema, $scope, $operator);

        if ($notSaved !== null) {
            return $notSaved;
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

    /**
     * The pattern the browser holds the reference to.
     *
     * Without a declaration in force, the Core's own format applies: the
     * application has said nothing about its references.
     */
    private function referencePattern(?TenantSchemaView $schema): string
    {
        if ($schema === null) {
            return '[A-Za-z0-9._:\-]+';
        }

        return $schema->uniqueKeyPattern;
    }

    /**
     * What the operator reads under the reference field.
     *
     * ADR-0122 §6 — the pattern is written **before** he types, in words
     * he understands: the reference is the key the application knows the
     * client by.
     */
    private function referenceHint(?TenantSchemaView $schema): string
    {
        if ($schema === null) {
            return 'L\'identifiant opaque que le projet connaît : lettres, chiffres et . _ : -';
        }

        return sprintf(
            'Le code que l\'application utilise : %s. Cette valeur devient la référence du client chez le Core.',
            TenantSchemaLabels::pattern($schema->uniqueKeyPattern),
        );
    }

    /**
     * The identity of the client, Core fields and declared fields merged.
     *
     * A declared key whose source is one of the Core's own identity fields
     * **replaces** it: the operator types the value once, and the console
     * never asks twice for the same thing (ADR-0122 §5).
     *
     * @param array{external_ref: string, display_name: string, email: ?string, phone: ?string, locale: string} $data
     * @param array<string, string> $declared
     * @return array{display_name: string, email: ?string, phone: ?string, locale: string}
     */
    private function identity(array $data, ?TenantSchemaView $schema, array $declared): array
    {
        $identity = [
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'locale' => $data['locale'],
        ];

        if ($schema === null) {
            return $identity;
        }

        foreach ($schema->fields as $field) {
            $core = TenantSchemaLabels::identityField($field->source);
            $value = $declared[$field->key] ?? null;

            if ($core === null || $value === null) {
                continue;
            }

            // The key that names the Core field is known: the shape of the
            // identity stays known too.
            match ($core) {
                'display_name' => $identity['display_name'] = $value,
                'email' => $identity['email'] = $value,
                'phone' => $identity['phone'] = $value,
                'locale' => $identity['locale'] = $value,
                default => null,
            };
        }

        return $identity;
    }

    /**
     * ADR-0122 §6 — the reference must respect the pattern in force.
     *
     * The refusal is made **here**, where the operator can still correct
     * it: the error lands under the reference field, not in a report read
     * later.
     */
    private function assertReferenceIsAccepted(string $projectId, string $reference, AccessScope $scope, ?TenantSchemaView $schema): ?RedirectResponse
    {
        if ($this->tenantSchemas->acceptsReference($projectId, $reference, $scope)) {
            return null;
        }

        $pattern = $schema?->uniqueKeyPattern;

        return back()->withInput()->withErrors([
            'external_ref' => $pattern === null
                ? 'Cette référence ne respecte pas le format en vigueur.'
                : sprintf(
                    'Cette référence ne respecte pas le format en vigueur : %s.',
                    TenantSchemaLabels::pattern($pattern),
                ),
        ]);
    }

    /**
     * The declared values of the customer, confronted to the form in force.
     *
     * Only the keys the declaration names are read: a hand-made request
     * cannot slip a key the Core does not know.
     *
     * @return array<string, string>
     */
    private function validateDeclared(Request $request, ?TenantSchemaView $schema): array
    {
        if ($schema === null || $schema->fields === []) {
            return [];
        }

        $rules = [];

        foreach ($schema->fields as $field) {
            $rules['attributes.'.$field->key] = $this->rulesFor($field);
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($request->all(), $rules)->validate();

        // `validate()` rend l'imbrication : les valeurs sont sous
        // « attributes », pas à plat. Les lire à plat les réduirait toutes à
        // null — et le locataire naîtreait sans les valeurs que l'opérateur
        // vient de saisir.
        $declarations = $validated['attributes'] ?? [];

        if (! is_array($declarations)) {
            $declarations = [];
        }

        $values = [];

        foreach ($schema->fields as $field) {
            $value = $declarations[$field->key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[$field->key] = trim($value);
            }
        }

        return $values;
    }

    /**
     * The rules a declared field is held to, from its declaration.
     *
     * @return list<string>
     */
    private function rulesFor(TenantSchemaFieldView $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable', 'string'];

        $rules = match ($field->type) {
            'integer' => [$field->required ? 'required' : 'nullable', 'integer'],
            'decimal' => [$field->required ? 'required' : 'nullable', 'numeric'],
            'boolean' => [$field->required ? 'required' : 'nullable', 'boolean'],
            'date' => [$field->required ? 'required' : 'nullable', 'date'],
            'datetime' => [$field->required ? 'required' : 'nullable', 'date'],
            'email' => [$field->required ? 'required' : 'nullable', 'email'],
            'url' => [$field->required ? 'required' : 'nullable', 'url'],
            default => $rules,
        };

        if ($field->maxLength !== null && in_array($field->type, ['string', 'text', 'email', 'phone', 'url'], true)) {
            $rules[] = 'max:'.$field->maxLength;
        }

        return $rules;
    }

    /**
     * The declared values are written **after** the customer: they hang
     * from it.
     *
     * @param array<string, string> $declared
     */
    private function saveDeclaredValues(
        string $projectId,
        string $customerId,
        array $declared,
        ?TenantSchemaView $schema,
        AccessScope $scope,
        OperatorContext $operator,
    ): ?RedirectResponse {
        if ($schema === null) {
            return null;
        }

        try {
            $this->setDeclaredAttributes->set(new SetCustomerDeclaredAttributesCommand(
                projectId: $projectId,
                customerId: $customerId,
                values: $declared,
                actor: $this->actor($operator),
                correlationId: null,
            ), $scope);
        } catch (CustomerDeclaredAttributeRefused) {
            return back()->withInput()->withErrors([
                'attributes' => 'Les valeurs déclarées n\'ont pas pu être enregistrées : la forme en vigueur a peut-être changé.',
            ]);
        }

        return null;
    }

    /**
     * The declared fields the console renders, in the order of the
     * declaration.
     *
     * @return list<array{key: string, label: string, type: string, options: array<array-key, string>, required: bool, maxLength: ?int, identity: ?string, hint: string}>
     */
    private function declaredFields(?TenantSchemaView $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $fields = [];

        foreach ($schema->fields as $field) {
            $fields[] = [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $this->inputType($field->type),
                'options' => $field->type === 'boolean' ? ['1' => 'Oui', '0' => 'Non'] : [],
                'required' => $field->required,
                'maxLength' => $field->maxLength,
                'identity' => TenantSchemaLabels::identityField($field->source),
                'hint' => $this->fieldHint($field),
            ];
        }

        return $fields;
    }

    /**
     * The control the console shows for a declared type.
     */
    private function inputType(string $type): string
    {
        return match ($type) {
            'text' => 'textarea',
            'integer', 'decimal' => 'number',
            'boolean' => 'select',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'email' => 'email',
            'phone' => 'tel',
            'url' => 'url',
            default => 'text',
        };
    }

    /**
     * The hint of a declared field: what it holds, and where it comes from.
     */
    private function fieldHint(TenantSchemaFieldView $field): string
    {
        $identity = TenantSchemaLabels::identityField($field->source);

        $hint = TenantSchemaLabels::type($field->type).'. ';

        $hint .= $identity === null
            ? 'Valeur déclarée par l\'application.'
            : sprintf('Cette valeur alimente %s du client.', TenantSchemaLabels::source($field->source));

        if ($field->maxLength !== null) {
            $hint .= sprintf(' %d caractères au plus.', $field->maxLength);
        }

        return $hint;
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
