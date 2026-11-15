<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\LedgerAccountLabels;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Ledger\Contracts\AccountStatement;
use Core\Ledger\Contracts\AccountStatementCommand;
use Core\Ledger\Contracts\AccountView;
use Core\Ledger\Contracts\ListAccounts;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accounts of a project — **all of them**, including the ones that
 * never moved — and, for one of them, its movements (ADR-0125 §6 §7,
 * ADR-0076 §4).
 *
 * Read-only, like the ledger page it completes: the Core creates the
 * accounts on demand, when a module needs one (ADR-0033 §4); the
 * operator never creates one, so there is no creation screen here.
 *
 * `?kind=` and `?reference=` designate one account of the project — the
 * currency tells which one when the same account exists in several
 * currencies. Its movements come from `AccountStatement`, the port that
 * was implemented and bound but never called (ADR-0125 §7).
 *
 * The movements are read through the project resolved in the operator's
 * scope, and only for an account that belongs to it: a project of another
 * organization is "not found" (404), never "forbidden" (S-04).
 */
final class ProjectLedgerAccountsController
{
    use ResolvesScopedProject;

    private const PAGE = 50;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListAccounts $listAccounts,
        private readonly AccountStatement $statement,
        private readonly LedgerAccountLabels $labels,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'wallets:read');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'wallets:read');

        $accounts = $this->listAccounts->accountsOf($projectId, $scope);
        $rows = $this->labels->rows($accounts, $projectId, $scope);

        $candidates = $this->candidates($request, $accounts);
        $selected = $this->selected($request, $candidates);

        $statement = null;

        if ($selected !== null) {
            $statement = $this->statement->statement(new AccountStatementCommand(
                projectId: $projectId,
                kind: $selected->kind,
                reference: $selected->reference,
                currency: $selected->currency,
                // The whole history of the account: the operator asks where
                // a balance comes from, not what happened last Tuesday.
                from: new DateTimeImmutable('1970-01-01'),
                to: $this->clock->now()->modify('+1 second'),
                cursor: $this->cursor($request),
                limit: self::PAGE,
            ), $scope);
        }

        return response()->view('admin.billing.ledger-accounts', [
            'title' => 'Comptes du projet',
            'project' => $project,
            'projectId' => $projectId,
            'rows' => $rows,
            'candidates' => $candidates,
            'selected' => $selected,
            'statement' => $statement,
            'nextCursor' => $statement?->nextCursor,
        ]);
    }

    /**
     * The accounts of the project the query designates.
     *
     * @param list<AccountView> $accounts
     * @return list<AccountView>
     */
    private function candidates(Request $request, array $accounts): array
    {
        $kind = $request->query('kind');
        $reference = $request->query('reference');

        if (! is_string($kind) || $kind === '' || ! is_string($reference) || $reference === '') {
            return [];
        }

        $candidates = array_values(array_filter(
            $accounts,
            static fn (AccountView $account): bool => $account->kind === $kind && $account->reference === $reference,
        ));

        if ($candidates === []) {
            abort(404, 'Compte introuvable.');
        }

        return $candidates;
    }

    /**
     * The account whose movements are shown.
     *
     * @param list<AccountView> $candidates
     */
    private function selected(Request $request, array $candidates): ?AccountView
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // The same account in several currencies: the currency tells which
        // one the operator is asking about.
        $currency = $request->query('currency');

        if (! is_string($currency) || $currency === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->currency === strtoupper($currency)) {
                return $candidate;
            }
        }

        return null;
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
