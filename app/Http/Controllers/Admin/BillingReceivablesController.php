<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Ledger\Contracts\TrialBalance;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0073 — the receivables of a project, read-only: the open invoices,
 * the ones that are late, and the ones written off.
 *
 * The **amounts** come from the ledger, never from a counter on the
 * invoice: what is really owed is what the books say. The ledger carries
 * the receivable per customer, so the totals here are the balance the
 * books hold — the rows listed below are the detail of that balance.
 *
 * The write-off is an act of the operator (ADR-0094 §12): its date is
 * the act's own, kept on the invoice.
 */
final class BillingReceivablesController
{
    use ResolvesScopedProject;

    /** How many invoices the screen lists. */
    private const LISTED = 200;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListInvoices $invoices,
        private readonly TrialBalance $ledger,
        private readonly Clock $clock,
    ) {}

    public function index(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:read');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'invoices:read');

        $now = $this->clock->now();

        $open = $this->invoices->list(
            new ListInvoicesCommand(projectId: $projectId, status: 'open', limit: self::LISTED),
            $scope,
        )->items;

        $writtenOff = $this->invoices->list(
            new ListInvoicesCommand(projectId: $projectId, status: 'uncollectible', limit: self::LISTED),
            $scope,
        )->items;

        $balances = $this->balances($projectId, $scope);

        return response()->view('admin.billing.receivables', [
            'title' => 'Créances',
            'project' => $project,
            'projectId' => $projectId,
            'open' => $this->rows($open, $now),
            'writtenOff' => array_map(
                static fn (InvoiceView $invoice): array => [
                    'invoice' => $invoice,
                    'remaining' => $invoice->totalMinor - $invoice->amountPaidMinor,
                    'lostAt' => $invoice->lostAt,
                ],
                $writtenOff,
            ),
            'owed' => $balances['receivable'],
            'badDebt' => $balances['bad_debt'],
            'late' => $this->late($open, $now),
        ]);
    }

    /**
     * The balances the books hold: what is owed, and what was given up.
     *
     * @return array{receivable: array<string, int>, bad_debt: array<string, int>}
     */
    private function balances(string $projectId, AccessScope $scope): array
    {
        $receivable = [];
        $badDebt = [];

        foreach ($this->ledger->forProjectScoped($projectId, $scope) as $account) {
            if ($account->kind === 'customer_receivable') {
                $receivable[$account->currency] = ($receivable[$account->currency] ?? 0) + $account->netDebitMinor();
            }

            if ($account->kind === 'bad_debt') {
                $badDebt[$account->currency] = ($badDebt[$account->currency] ?? 0) + $account->netDebitMinor();
            }
        }

        return ['receivable' => $receivable, 'bad_debt' => $badDebt];
    }

    /**
     * The open invoices, each with what remains to be paid and how late it
     * is — `null` when the invoice has no due date or is not yet due.
     *
     * @param list<InvoiceView> $invoices
     * @return list<array{invoice: InvoiceView, remaining: int, daysLate: int|null}>
     */
    private function rows(array $invoices, DateTimeImmutable $now): array
    {
        $rows = [];

        foreach ($invoices as $invoice) {
            $daysLate = null;

            if ($invoice->dueAt !== null) {
                $due = new DateTimeImmutable($invoice->dueAt);

                if ($due <= $now) {
                    $daysLate = $now->diff($due)->days;
                }
            }

            $rows[] = [
                'invoice' => $invoice,
                'remaining' => $invoice->totalMinor - $invoice->amountPaidMinor,
                'daysLate' => $daysLate,
            ];
        }

        return $rows;
    }

    /**
     * What is due and not paid: the invoices whose due date has passed.
     *
     * @param list<InvoiceView> $invoices
     * @return array<string, int> per currency
     */
    private function late(array $invoices, DateTimeImmutable $now): array
    {
        $late = [];

        foreach ($invoices as $invoice) {
            if ($invoice->dueAt === null) {
                continue;
            }

            if (new DateTimeImmutable($invoice->dueAt) > $now) {
                continue;
            }

            $remaining = $invoice->totalMinor - $invoice->amountPaidMinor;
            $late[$invoice->currency] = ($late[$invoice->currency] ?? 0) + $remaining;
        }

        return $late;
    }
}
