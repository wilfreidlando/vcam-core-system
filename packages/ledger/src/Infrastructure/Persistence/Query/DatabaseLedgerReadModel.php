<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Query;

use Core\Ledger\Contracts\AccountBalanceView;
use Core\Ledger\Contracts\AccountStatement;
use Core\Ledger\Contracts\AccountStatementCommand;
use Core\Ledger\Contracts\AccountStatementLine;
use Core\Ledger\Contracts\AccountStatementPage;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\LedgerTransactionLine;
use Core\Ledger\Contracts\LedgerTransactionPage;
use Core\Ledger\Contracts\LedgerTransactionView;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read side of the ledger (ADR-0076 §4): plain SQL over the module's own
 * tables, exposed only through `Contracts/`. Read-only by construction —
 * the ledger tables refuse any change anyway (triggers).
 */
final class DatabaseLedgerReadModel implements AccountStatement, FindPostedLedgerKeys, ListLedgerTransactions, TrialBalance
{
    private const MAX_LIMIT = 200;

    private const KEYS_CHUNK = 500;

    public function list(ListLedgerTransactionsCommand $command): LedgerTransactionPage
    {
        return $this->read($command);
    }

    /**
     * ADR-0069 §2 / G16 — the console's read. The scope is a required
     * parameter (never a default), so a caller that may not see the
     * project reads nothing at all: an empty page, never another
     * project's transactions.
     */
    public function listScoped(ListLedgerTransactionsCommand $command, AccessScope $scope): LedgerTransactionPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new LedgerTransactionPage(items: [], nextCursor: null);
        }

        return $this->read($command);
    }

    /**
     * ADR-0076 §4 — the statement of ONE account over `[from, to)`.
     *
     * Chronological (oldest first), keyset-paginated on
     * `(created_at, id)` — the same mechanism `ListLedgerTransactions`
     * uses, read the other way round: the cursor of a statement is the
     * last line read, so a page neither skips nor repeats an entry.
     */
    public function statement(AccountStatementCommand $command): AccountStatementPage
    {
        $limit = max(1, min(self::MAX_LIMIT, $command->limit));

        $accountId = $this->accountId($command);

        // An account that was never created (nothing was ever posted to
        // it) is not an error: it opens and closes on zero.
        if ($accountId === null) {
            return new AccountStatementPage(
                lines: [],
                nextCursor: null,
                openingBalanceMinor: 0,
                closingBalanceMinor: 0,
                currency: $command->currency,
            );
        }

        $from = $command->from->format(DateTimeInterface::ATOM);
        $to = $command->to->format(DateTimeInterface::ATOM);

        // The opening balance is what the account held strictly BEFORE
        // `from`; the period is `[from, to)` — `to` excluded, so two
        // consecutive periods never state the same movement twice.
        $opening = $this->net($accountId, null, $from);
        $period = $this->net($accountId, $from, $to);

        $query = DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_entries.account_id', $accountId)
            ->where('ledger_entries.created_at', '>=', $from)
            ->where('ledger_entries.created_at', '<', $to);

        if ($command->cursor !== null) {
            [$createdAt, $id] = self::decodeCursor($command->cursor);
            $query->where(static function (Builder $q) use ($createdAt, $id): void {
                $q->where('ledger_entries.created_at', '>', $createdAt)
                    ->orWhere(static function (Builder $q2) use ($createdAt, $id): void {
                        $q2->where('ledger_entries.created_at', '=', $createdAt)->where('ledger_entries.id', '>', (int) $id);
                    });
            });
        }

        $rows = $query
            ->orderBy('ledger_entries.created_at')
            ->orderBy('ledger_entries.id')
            ->limit($limit + 1)
            ->get([
                'ledger_entries.id',
                'ledger_entries.transaction_id',
                'ledger_entries.direction',
                'ledger_entries.amount_minor',
                'ledger_entries.created_at',
                'ledger_transactions.type',
                'ledger_transactions.external_ref',
            ])
            ->all();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $lines = [];
        // The running balance is the ACCOUNT's balance, not the page's:
        // a line states where the account stands once that movement is
        // booked, whatever page it was read on. The balance the page
        // starts from is therefore the opening plus everything the period
        // moved before the page's first line.
        $balance = $opening + $this->netBeforeLine($accountId, $from, $rows[0] ?? null);
        foreach ($rows as $row) {
            $amount = self::int($row, 'amount_minor');
            $direction = Direction::from(self::str($row, 'direction'));
            $balance += $direction === Direction::Credit ? -$amount : $amount;

            $lines[] = new AccountStatementLine(
                entryId: self::int($row, 'id'),
                transactionId: self::str($row, 'transaction_id'),
                transactionType: self::str($row, 'type'),
                externalRef: self::optStr($row, 'external_ref'),
                createdAt: new DateTimeImmutable(self::str($row, 'created_at')),
                direction: $direction->value,
                amountMinor: $amount,
                balanceAfterMinor: $balance,
            );
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new AccountStatementPage(
            lines: $lines,
            nextCursor: $hasMore && $last !== null
                ? self::encodeCursor(self::str($last, 'created_at'), self::str($last, 'id'))
                : null,
            openingBalanceMinor: $opening,
            closingBalanceMinor: $opening + $period,
            currency: $command->currency,
        );
    }

    public function forProjectScoped(string $projectId, AccessScope $scope, ?DateTimeImmutable $at = null): array
    {
        return $scope->allowsProject($projectId) ? $this->forProject($projectId, $at) : [];
    }

    public function forProject(string $projectId, ?DateTimeImmutable $at = null): array
    {
        $query = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.project_id', $projectId);
        if ($at !== null) {
            $query->where('ledger_entries.created_at', '<', $at->format(DateTimeInterface::ATOM));
        }

        $rows = $query
            ->groupBy('ledger_accounts.kind', 'ledger_accounts.reference', 'ledger_accounts.currency')
            ->orderBy('ledger_accounts.currency')
            ->orderBy('ledger_accounts.kind')
            ->orderBy('ledger_accounts.reference')
            ->get([
                'ledger_accounts.kind',
                'ledger_accounts.reference',
                'ledger_accounts.currency',
                DB::raw("COALESCE(SUM(CASE WHEN ledger_entries.direction = 'debit' THEN ledger_entries.amount_minor ELSE 0 END), 0) AS debit_minor"),
                DB::raw("COALESCE(SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE 0 END), 0) AS credit_minor"),
            ])
            ->all();

        $balances = [];
        foreach ($rows as $row) {
            $balances[] = new AccountBalanceView(
                kind: self::str($row, 'kind'),
                reference: self::str($row, 'reference'),
                currency: self::str($row, 'currency'),
                debitMinor: self::int($row, 'debit_minor'),
                creditMinor: self::int($row, 'credit_minor'),
            );
        }

        return $balances;
    }

    public function findPosted(string $projectId, array $idempotencyKeys): array
    {
        $posted = [];
        foreach (array_chunk(array_values(array_unique($idempotencyKeys)), self::KEYS_CHUNK) as $chunk) {
            foreach (DB::table('ledger_transactions')->where('project_id', $projectId)->whereIn('idempotency_key', $chunk)->pluck('idempotency_key') as $key) {
                if (is_string($key)) {
                    $posted[] = $key;
                }
            }
        }

        return $posted;
    }

    private function read(ListLedgerTransactionsCommand $command): LedgerTransactionPage
    {
        $limit = max(1, min(self::MAX_LIMIT, $command->limit));

        $query = DB::table('ledger_transactions')->where('project_id', $command->projectId);
        if ($command->from !== null) {
            $query->where('created_at', '>=', $command->from->format(DateTimeInterface::ATOM));
        }
        if ($command->to !== null) {
            $query->where('created_at', '<', $command->to->format(DateTimeInterface::ATOM));
        }
        if ($command->types !== null) {
            $query->whereIn('type', $command->types);
        }
        if ($command->cursor !== null) {
            [$createdAt, $id] = self::decodeCursor($command->cursor);
            $query->where(static function (Builder $q) use ($createdAt, $id): void {
                $q->where('created_at', '<', $createdAt)
                    ->orWhere(static function (Builder $q2) use ($createdAt, $id): void {
                        $q2->where('created_at', '=', $createdAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get()->all();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = self::str($row, 'id');
        }
        $lines = $this->linesFor($ids);

        $items = [];
        foreach ($rows as $row) {
            $id = self::str($row, 'id');
            $items[] = new LedgerTransactionView(
                id: $id,
                projectId: self::str($row, 'project_id'),
                type: self::str($row, 'type'),
                externalRef: self::optStr($row, 'external_ref'),
                idempotencyKey: self::str($row, 'idempotency_key'),
                reversesTransactionId: self::optStr($row, 'reverses_transaction_id'),
                createdAt: (new DateTimeImmutable(self::str($row, 'created_at')))->format(DateTimeInterface::ATOM),
                lines: $lines[$id] ?? [],
            );
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new LedgerTransactionPage(
            items: $items,
            nextCursor: $hasMore && $last !== null ? self::encodeCursor(self::str($last, 'created_at'), self::str($last, 'id')) : null,
        );
    }

    /**
     * @param list<string> $transactionIds
     * @return array<string, list<LedgerTransactionLine>>
     */
    private function linesFor(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->whereIn('ledger_entries.transaction_id', $transactionIds)
            ->orderBy('ledger_entries.transaction_id')
            ->orderByDesc('ledger_entries.direction')
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.transaction_id', 'ledger_accounts.kind', 'ledger_accounts.reference', 'ledger_entries.direction', 'ledger_entries.amount_minor', 'ledger_entries.currency'])
            ->all();

        $lines = [];
        foreach ($rows as $row) {
            $lines[self::str($row, 'transaction_id')][] = new LedgerTransactionLine(
                accountKind: self::str($row, 'kind'),
                accountReference: self::str($row, 'reference'),
                direction: self::str($row, 'direction'),
                amountMinor: self::int($row, 'amount_minor'),
                currency: self::str($row, 'currency'),
            );
        }

        return $lines;
    }

    /**
     * The id of the account a statement is about, or null when the chart
     * of accounts has never seen it.
     */
    private function accountId(AccountStatementCommand $command): ?string
    {
        // The kind is validated the way the ledger validates it everywhere
        // else: a malformed kind is a caller's mistake, not an empty
        // statement.
        $kind = AccountKind::fromString($command->kind);

        $row = DB::table('ledger_accounts')
            ->where('project_id', $command->projectId)
            ->where('kind', (string) $kind)
            ->where('reference', $command->reference)
            ->where('currency', $command->currency)
            ->first(['id']);

        return $row === null ? null : self::str($row, 'id');
    }

    /**
     * The net movement of an account over a period — debits minus
     * credits. A null bound is an open one: `null` from means "since the
     * beginning", a null `to` means "until now".
     */
    private function net(string $accountId, ?string $from, ?string $to): int
    {
        $query = DB::table('ledger_entries')->where('account_id', $accountId);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        $row = $query
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END), 0) AS net_minor")
            ->first();

        return $row === null ? 0 : self::int($row, 'net_minor');
    }

    /**
     * What the period moved BEFORE a given line — the balance the page
     * that starts at that line must open on.
     *
     * @param object|null $row the first line of the page, or null when the
     *                         page is empty
     */
    private function netBeforeLine(string $accountId, string $from, ?object $row): int
    {
        if ($row === null) {
            return 0;
        }

        $net = DB::table('ledger_entries')
            ->where('account_id', $accountId)
            ->where('created_at', '>=', $from)
            // The row comparison is the same order the page is read in:
            // an entry of the same instant but of a smaller id comes
            // before the line, and is part of the balance it opens on.
            ->whereRaw('(created_at, id) < (?, ?)', [
                self::str($row, 'created_at'),
                self::int($row, 'id'),
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END), 0) AS net_minor")
            ->first();

        return $net === null ? 0 : self::int($net, 'net_minor');
    }

    private static function encodeCursor(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode($createdAt.'|'.$id), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = is_string($decoded) ? explode('|', $decoded, 2) : [];
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid ledger cursor.');
        }

        return [$parts[0], $parts[1]];
    }

    private static function str(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function optStr(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
