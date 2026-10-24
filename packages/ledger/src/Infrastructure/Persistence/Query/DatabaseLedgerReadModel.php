<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Query;

use Core\Ledger\Contracts\AccountBalanceView;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\LedgerTransactionLine;
use Core\Ledger\Contracts\LedgerTransactionPage;
use Core\Ledger\Contracts\LedgerTransactionView;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
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
final class DatabaseLedgerReadModel implements FindPostedLedgerKeys, ListLedgerTransactions, TrialBalance
{
    private const MAX_LIMIT = 200;

    private const KEYS_CHUNK = 500;

    public function list(ListLedgerTransactionsCommand $command): LedgerTransactionPage
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
