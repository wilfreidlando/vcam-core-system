<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ApproveManualReceiptCommand;
use Core\Billing\Contracts\ListManualReceipts;
use Core\Billing\Contracts\ListManualReceiptsCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Exception\ManualReceiptApprovalRefused;
use Core\Billing\Domain\Exception\ManualReceiptNotFound;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The offline collection of an invoice — ADR-0073 §4, ADR-0094 §13.
 *
 * The console calls **exactly** the same published contracts as the
 * API would: `RecordManualReceipt`, `ApproveManualReceipt` and
 * `ListManualReceipts`. A gesture the API refuses is refused here too.
 *
 * Every gesture of the operator is an **audited act**: the receipt
 * names what arrived, and the audit entry keeps the fields that changed
 * — never a personal value (ADR-0083 §4).
 */
final class BillingInvoiceReceiptsController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListManualReceipts $listManualReceipts,
        private readonly RecordManualReceipt $recordManualReceipt,
        private readonly ApproveManualReceipt $approveManualReceipt,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * The receipts waiting for a second account — the four eyes of
     * ADR-0073 §4.
     */
    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:read');

        $page = $this->listManualReceipts->list(new ListManualReceiptsCommand(
            projectId: $projectId,
            status: $this->query($request, 'status') ?? 'pending',
            cursor: $this->query($request, 'cursor'),
            limit: min(100, max(1, (int) $request->query('limit', 50))),
        ), $scope);

        return response()->view('admin.billing.invoices.receipts', [
            'title' => 'Encaissements hors ligne',
            'projectId' => $projectId,
            'receipts' => $page->items,
            'nextCursor' => $page->nextCursor,
            'status' => $this->query($request, 'status') ?? 'pending',
        ]);
    }

    /**
     * The recording of an offline collection.
     *
     * Above the project's threshold, the receipt waits for a second
     * console account: the author can never approve their own entry
     * (ADR-0073 §4, ADR-0094 §13).
     */
    public function store(Request $request, OperatorContext $operator, string $projectId, string $invoiceId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        /** @var array{method: string, reference: string, amount_minor: int|string, currency: string, received_at: string, evidence_ref?: string, note?: string} $data */
        $data = Validator::make($request->all(), [
            'method' => ['required', 'string', Rule::in(['bank_transfer', 'cash', 'cheque', 'other'])],
            'reference' => ['required', 'string', 'max:200'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'received_at' => ['required', 'string', 'date'],
            'evidence_ref' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $receipt = $this->recordManualReceipt->record(new RecordManualReceiptCommand(
                projectId: $projectId,
                invoiceId: $invoiceId,
                method: $data['method'],
                reference: $data['reference'],
                amountMinor: (int) $data['amount_minor'],
                currency: $data['currency'],
                receivedAt: $data['received_at'],
                recordedBy: $operator->operatorId,
                evidenceRef: $data['evidence_ref'] ?? null,
                note: $data['note'] ?? null,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (
            InvoiceNotFound|InvoiceNotPayable|ManualReceiptNotFound|ManualReceiptApprovalRefused $exception
        ) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.invoice.receipt_recorded',
            resource: 'invoice_receipt:'.$receipt->id,
            before: null,
            after: ['changed_fields' => ['status', 'amount_paid_minor']],
            context: [
                'project_id' => $projectId,
                'invoice_id' => $invoiceId,
                'method' => $receipt->method,
            ],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.invoices.show', [$projectId, $invoiceId])
            ->with('status', $receipt->status === 'pending'
                ? 'Encaissement hors ligne enregistré : il attend sa validation.'
                : 'Encaissement hors ligne enregistré et comptabilisé.');
    }

    /**
     * The validation of an offline receipt — ADR-0073 §4, ADR-0095.
     *
     * One console account is enough (ADR-0095 withdrew the four eyes):
     * the author may validate their own entry. What the Core asks for
     * is a deliberate act, recorded and audited.
     */
    public function approve(Request $request, OperatorContext $operator, string $projectId, string $receiptId): RedirectResponse
    {
        $this->permittedScope($operator, 'invoices:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        $approved = $request->input('decision', 'approve') === 'approve';

        try {
            $receipt = $this->approveManualReceipt->approve(new ApproveManualReceiptCommand(
                projectId: $projectId,
                receiptId: $receiptId,
                approver: $operator->operatorId,
                approved: $approved,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (
            InvoiceNotFound|InvoiceNotPayable|ManualReceiptNotFound|ManualReceiptApprovalRefused $exception
        ) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: $approved
                ? 'billing.invoice.receipt_approved'
                : 'billing.invoice.receipt_rejected',
            resource: 'invoice_receipt:'.$receipt->id,
            before: null,
            after: ['changed_fields' => ['status', 'amount_paid_minor']],
            context: [
                'project_id' => $projectId,
                'invoice_id' => $receipt->invoiceId,
            ],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.receipts.index', [$projectId])
            ->with('status', $approved
                ? 'Encaissement hors ligne validé : il est comptabilisé.'
                : 'Encaissement hors ligne refusé.');
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
