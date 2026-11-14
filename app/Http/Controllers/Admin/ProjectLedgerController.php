<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\LedgerAccountLabels;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Ledger\Contracts\AccountView;
use Core\Ledger\Contracts\ListAccounts;
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
        private readonly ListAccounts $listAccounts,
        private readonly LedgerAccountLabels $labels,
    ) {}

    public function show(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'wallets:read');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'wallets:read');

        $accounts = $this->balance->forProjectScoped($projectId, $scope);

        // Per currency: the accounts and the check the hourly job makes (ADR-0078 §2).
        $byCurrency = [];

        foreach ($accounts as $account) {
            $byCurrency[$account->currency]['accounts'][] = new AccountView(
                kind: $account->kind,
                reference: $account->reference,
                currency: $account->currency,
                debitMinor: $account->debitMinor,
                creditMinor: $account->creditMinor,
            );
            $byCurrency[$account->currency]['net'] = ($byCurrency[$account->currency]['net'] ?? 0) + $account->netDebitMinor();
        }

        ksort($byCurrency);

        // The balance says where the project stands; the chart of accounts
        // says which accounts exist — including the ones that never moved
        // (ADR-0125 §6).
        $allAccounts = $this->listAccounts->accountsOf($projectId, $scope);

        foreach ($byCurrency as $currency => $block) {
            $byCurrency[$currency]['rows'] = $this->labels->rows($block['accounts'], $projectId, $scope);
        }

        // What each account's reference points to, for the lines of the
        // transactions: a raw identifier never reaches the screen.
        $explanations = [];

        foreach ($allAccounts as $account) {
            $explanations[$account->kind.'|'.$account->reference] = $this->labels->explanation($account->kind, $account->reference, $projectId, $scope);
        }

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
            'accountRows' => $this->labels->rows($allAccounts, $projectId, $scope),
            'explanations' => $explanations,
            'transactions' => $page->items,
            'nextCursor' => $page->nextCursor,
            'type' => is_string($type) ? $type : '',
        ]);
    }
}
