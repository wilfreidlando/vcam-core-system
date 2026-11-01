<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ledger of one project, read-only (ADR-0076 §4, ADR-0078 §2): the trial
 * balance per currency — whose net debits must sum to zero — and the
 * transactions, newest first, by cursor.
 *
 * Nothing here writes: a correction is a new transaction proposed in
 * « Corrections d'exploitation », never an edit of an entry.
 */
final class ProjectLedgerController
{
    use ResolvesScopedProject;

    private const PAGE = 30;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly TrialBalance $balance,
        private readonly ListLedgerTransactions $transactions,
    ) {}

    public function show(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'wallets:read');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'wallets:read');

        $accounts = $this->balance->forProjectScoped($projectId, $scope);

        // Per currency: the accounts and the check the hourly job makes (ADR-0078 §2).
        $byCurrency = [];

        foreach ($accounts as $account) {
            $byCurrency[$account->currency]['accounts'][] = $account;
            $byCurrency[$account->currency]['net'] = ($byCurrency[$account->currency]['net'] ?? 0) + $account->netDebitMinor();
        }

        ksort($byCurrency);

        $cursor = $request->query('cursor');
        $type = $request->query('type');

        $page = $this->transactions->listScoped(new ListLedgerTransactionsCommand(
            projectId: $projectId,
            types: is_string($type) && $type !== '' ? [$type] : null,
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
            limit: self::PAGE,
        ), $scope);

        return response()->view('admin.billing.ledger', [
            'title' => 'Grand livre',
            'project' => $project,
            'projectId' => $projectId,
            'currencies' => $byCurrency,
            'transactions' => $page->items,
            'nextCursor' => $page->nextCursor,
            'type' => is_string($type) ? $type : '',
        ]);
    }
}
