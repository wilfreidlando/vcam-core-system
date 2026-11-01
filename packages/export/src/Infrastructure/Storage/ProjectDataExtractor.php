<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Storage;

use Core\Billing\Contracts\BillingExportRecords;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Core\Notification\Contracts\NotificationExportRecords;
use Core\Payment\Contracts\PaymentExportRecords;
use Core\Support\Access\AccessScope;
use Core\Wallet\Contracts\WalletExportRecords;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Reads everything a project produced, **through the other modules'
 * `Contracts/`**, in bounded batches, and writes one JSON Lines file per
 * type of object (ADR-0088 §3).
 *
 * Nothing is read from a table: the modules hand over their rows in the
 * shape of the public API, so an erased customer stays erased and the export
 * cannot show what the API would not. Memory is bounded by one batch.
 */
final class ProjectDataExtractor
{
    private const BATCH = 100;

    /** @var int a guard against a cursor that never ends */
    private const MAX_PAGES = 1_000_000;

    public function __construct(
        private readonly BillingExportRecords $billing,
        private readonly PaymentExportRecords $payments,
        private readonly NotificationExportRecords $notifications,
        private readonly WalletExportRecords $wallets,
        private readonly ListLedgerTransactions $ledgerTransactions,
        private readonly TrialBalance $trialBalance,
    ) {}

    /**
     * @return array<string, int> file name => number of rows
     */
    public function extract(string $projectId, string $exportId, DateTimeImmutable $asOf, string $directory): array
    {
        $counts = [];

        foreach ($this->billing->types() as $type) {
            $counts[$type.'.jsonl'] = $this->write($directory, $type, fn (?string $cursor): array => $this->billing->page($type, $projectId, $cursor, self::BATCH));
        }

        $counts['payments.jsonl'] = $this->write($directory, 'payments', fn (?string $cursor): array => $this->payments->page($projectId, $cursor, self::BATCH));
        $counts['notifications.jsonl'] = $this->write($directory, 'notifications', fn (?string $cursor): array => $this->notifications->page($projectId, $cursor, self::BATCH));

        foreach ($this->wallets->types() as $type) {
            $counts[$type.'.jsonl'] = $this->write($directory, $type, fn (?string $cursor): array => $this->wallets->page($type, $projectId, $cursor, self::BATCH));
        }

        $counts['ledger_transactions.jsonl'] = $this->write($directory, 'ledger_transactions', fn (?string $cursor): array => $this->ledgerPage($projectId, $asOf, $cursor));
        $counts['trial_balance.jsonl'] = $this->trialBalanceFile($directory, $projectId, $asOf);

        $manifest = [
            'export_id' => $exportId,
            'project_id' => $projectId,
            'as_of' => $asOf->format(DateTimeInterface::RFC3339),
            'format' => 'jsonl',
            'note' => 'ledger_transactions.jsonl and trial_balance.jsonl are exact at as_of; the other files were read while the export ran.',
            'files' => array_map(static fn (int $rows): array => ['rows' => $rows], $counts),
        ];
        $this->put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $counts;
    }

    /**
     * @param callable(?string): array{rows: list<array<string, mixed>>, next_cursor: string|null} $page
     */
    private function write(string $directory, string $name, callable $page): int
    {
        $handle = fopen($directory.'/'.$name.'.jsonl', 'wb');

        if ($handle === false) {
            throw new RuntimeException('The export file could not be created.');
        }

        $rows = 0;
        $cursor = null;

        try {
            for ($i = 0; $i < self::MAX_PAGES; $i++) {
                $result = $page($cursor);

                foreach ($result['rows'] as $row) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                    $rows++;
                }

                $cursor = $result['next_cursor'];

                if ($cursor === null) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function ledgerPage(string $projectId, DateTimeImmutable $asOf, ?string $cursor): array
    {
        $page = $this->ledgerTransactions->listScoped(
            new ListLedgerTransactionsCommand(projectId: $projectId, to: $asOf, cursor: $cursor, limit: self::BATCH),
            AccessScope::forProject($projectId),
        );

        $rows = [];
        foreach ($page->items as $transaction) {
            $rows[] = [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'external_ref' => $transaction->externalRef,
                'idempotency_key' => $transaction->idempotencyKey,
                'reverses_transaction_id' => $transaction->reversesTransactionId,
                'created_at' => $transaction->createdAt,
                'lines' => array_map(static fn ($line): array => [
                    'account_kind' => $line->accountKind,
                    'account_reference' => $line->accountReference,
                    'direction' => $line->direction,
                    'amount_minor' => $line->amountMinor,
                    'currency' => $line->currency,
                ], $transaction->lines),
            ];
        }

        return ['rows' => $rows, 'next_cursor' => $page->nextCursor];
    }

    private function trialBalanceFile(string $directory, string $projectId, DateTimeImmutable $asOf): int
    {
        $balances = $this->trialBalance->forProjectScoped($projectId, AccessScope::forProject($projectId), $asOf);
        $lines = '';

        foreach ($balances as $account) {
            $lines .= json_encode([
                'account_kind' => $account->kind,
                'account_reference' => $account->reference,
                'currency' => $account->currency,
                'debit_minor' => $account->debitMinor,
                'credit_minor' => $account->creditMinor,
                'net_debit_minor' => $account->netDebitMinor(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }

        $this->put($directory.'/trial_balance.jsonl', $lines);

        return count($balances);
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('The export file could not be written.');
        }
    }
}
