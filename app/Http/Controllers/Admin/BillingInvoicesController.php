<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\ListInvoicePayments;
use Core\Billing\Contracts\ListInvoicePaymentsCommand;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Billing\Contracts\ListManualReceipts;
use Core\Billing\Contracts\ListManualReceiptsCommand;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MarkInvoiceUncollectibleCommand;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Exception\InvalidInvoiceTransition;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invoices of a project (ADR-0073) — the console mirror of the public
 * API.
 *
 * The console calls **exactly** the same published contracts as the API:
 * `CreateInvoice`, `IssueInvoice`, `VoidInvoice`,
 * `MarkInvoiceUncollectible`, `GetInvoice` and `ListInvoices`. A gesture
 * that the API refuses is refused here too.
 *
 * Every gesture of the operator is an **audited act**: the issuance, the
 * cancellation and the write-off carry a reason, and the reason is kept in
 * the audit entry — the fields that changed, never their values
 * (ADR-0083 §4).
 *
 * The console never shows a personal value in clear (ADR-0093 §6): the
 * invoice is named by the reference the project knows its customer by, and
 * the frozen contact stays inside the printable document.
 */
final class BillingInvoicesController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListInvoices $listInvoices,
        private readonly GetInvoice $getInvoice,
        private readonly ListInvoicePayments $listInvoicePayments,
        private readonly ListManualReceipts $listManualReceipts,
        private readonly CreateInvoice $createInvoice,
        private readonly IssueInvoice $issueInvoice,
        private readonly VoidInvoice $voidInvoice,
        private readonly MarkInvoiceUncollectible $markUncollectible,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:read');

        $page = $this->listInvoices->list(new ListInvoicesCommand(
            projectId: $projectId,
            customerRef: $this->query($request, 'customer_ref'),
            status: $this->query($request, 'status'),
            cursor: $this->query($request, 'cursor'),
            limit: min(100, max(1, (int) $request->query('limit', 50))),
        ), $scope);

        return response()->view('admin.billing.invoices.index', [
            'title' => 'Factures',
            'projectId' => $projectId,
            'invoices' => $page->items,
            'nextCursor' => $page->nextCursor,
            'status' => $this->query($request, 'status'),
        ]);
    }

    public function show(OperatorContext $operator, string $projectId, string $invoiceId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:read');

        $invoice = $this->getInvoice->get(
            new GetInvoiceCommand($projectId, $invoiceId),
            $scope,
        );

        if ($invoice === null) {
            abort(404, 'Facture introuvable.');
        }

        return response()->view('admin.billing.invoices.show', [
            'title' => sprintf('Facture %s', $invoice->number ?? 'brouillon'),
            'projectId' => $projectId,
            'invoice' => $invoice,
            // ADR-0073 §2, §4 — the collection history of the invoice:
            // the online attempts and the offline receipts.
            'payments' => $this->listInvoicePayments->list(new ListInvoicePaymentsCommand(
                projectId: $projectId,
                invoiceId: $invoice->id,
                limit: 50,
            ), $scope)->items,
            'receipts' => $this->listManualReceipts->list(new ListManualReceiptsCommand(
                projectId: $projectId,
                invoiceId: $invoice->id,
                limit: 50,
            ), $scope)->items,
        ]);
    }

    public function create(OperatorContext $operator, string $projectId): Response
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        return response()->view('admin.billing.invoices.form', [
            'title' => 'Nouvelle facture',
            'projectId' => $projectId,
            'invoice' => null,
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        /** @var array{customer_ref: string, currency?: string|null, tax_rate_bps?: int|null, due_at?: string|null, lines: array<int, array{description: string, quantity: int, unit_amount_minor: int, tax_rate_bps?: int|null}>} $data */
        $data = Validator::make($request->all(), [
            'customer_ref' => ['required', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'due_at' => ['nullable', 'string', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_amount_minor' => ['required', 'integer', 'min:0'],
            'lines.*.tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ])->validate();

        $lines = array_values(array_map(
            static fn (array $line): InvoiceLineInput => new InvoiceLineInput(
                description: $line['description'],
                quantity: $line['quantity'],
                unitAmountMinor: $line['unit_amount_minor'],
                taxRateBps: $line['tax_rate_bps'] ?? null,
            ),
            $data['lines'],
        ));

        try {
            $invoice = $this->createInvoice->create(new CreateInvoiceCommand(
                projectId: $projectId,
                customerRef: $data['customer_ref'],
                lines: $lines,
                dueAt: $data['due_at'] ?? null,
                currency: $data['currency'] ?? null,
                taxRateBps: $data['tax_rate_bps'] ?? null,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (CustomerNotFound|InvalidCustomerTransition|InvalidInvoiceTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.invoice.created',
            resource: 'invoice:'.$invoice->id,
            before: null,
            after: ['changed_fields' => ['status', 'total_minor']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.invoices.show', [$projectId, $invoice->id])
            ->with('status', 'Facture créée en brouillon.');
    }

    public function issue(OperatorContext $operator, string $projectId, string $invoiceId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        try {
            $invoice = $this->issueInvoice->issue(new IssueInvoiceCommand(
                projectId: $projectId,
                invoiceId: $invoiceId,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (InvoiceNotFound|InvalidInvoiceTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.invoice.issued',
            resource: 'invoice:'.$invoice->id,
            before: null,
            after: ['changed_fields' => ['status', 'number']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.invoices.show', [$projectId, $invoice->id])
            ->with('status', sprintf('Facture %s émise.', (string) $invoice->number));
    }

    public function void(Request $request, OperatorContext $operator, string $projectId, string $invoiceId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        $reason = $this->reason($request);

        try {
            $invoice = $this->voidInvoice->void(new VoidInvoiceCommand(
                projectId: $projectId,
                invoiceId: $invoiceId,
                reason: $reason,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (InvoiceNotFound|InvalidInvoiceTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.invoice.voided',
            resource: 'invoice:'.$invoice->id,
            before: null,
            after: ['changed_fields' => ['status']],
            context: [
                'project_id' => $projectId,
                'reason' => $reason,
            ],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.invoices.show', [$projectId, $invoice->id])
            ->with('status', 'Facture annulée.');
    }

    public function uncollectible(Request $request, OperatorContext $operator, string $projectId, string $invoiceId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        $reason = $this->reason($request);

        try {
            $invoice = $this->markUncollectible->markUncollectible(new MarkInvoiceUncollectibleCommand(
                projectId: $projectId,
                invoiceId: $invoiceId,
                reason: $reason,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (InvoiceNotFound|InvalidInvoiceTransition $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.invoice.uncollectible',
            resource: 'invoice:'.$invoice->id,
            before: null,
            after: ['changed_fields' => ['status']],
            context: [
                'project_id' => $projectId,
                'reason' => $reason,
            ],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.invoices.show', [$projectId, $invoice->id])
            ->with('status', 'Créance passée en irrécouvrable.');
    }

    /**
     * The operator's acts carry a reason (ADR-0072 §6, ADR-0073 §3) — an
     * act without one is refused.
     */
    private function reason(Request $request): string
    {
        /** @var array{reason: string} $data */
        $data = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        $reason = trim($data['reason']);

        if ($reason === '') {
            abort(422, 'Le motif est obligatoire.');
        }

        return $reason;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
