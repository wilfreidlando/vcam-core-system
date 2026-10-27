<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0076 §4 — "Pagination par curseur (created_at, id), INDEX
 * ADAPTÉS".
 *
 * The account statement filters on `account_id` and orders by
 * `(created_at, id)`. With an index on `account_id` alone, PostgreSQL
 * reads the whole history of the account and sorts it again at every
 * page — the statement got slower as the account aged.
 *
 * The index is asserted on the MIGRATED schema, not on the migration
 * file (ADR-0023): what matters is the database the application really
 * runs on.
 */
final class LedgerStatementIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_entries_are_indexed_for_the_account_statement(): void
    {
        $indexes = $this->indexesOf('ledger_entries');

        self::assertArrayHasKey(
            'ledger_entries_account_id_created_at_id_index',
            $indexes,
            'The account statement reads (account_id) and orders by (created_at, id): the index must serve both.',
        );
        self::assertStringContainsString('(account_id, created_at, id)', $indexes['ledger_entries_account_id_created_at_id_index']);
    }

    #[Test]
    public function the_single_column_index_on_the_account_is_gone(): void
    {
        $indexes = $this->indexesOf('ledger_entries');

        self::assertArrayNotHasKey(
            'ledger_entries_account_id_index',
            $indexes,
            'The composite index starts with `account_id`: the single-column one is redundant and costs writes.',
        );
    }

    /**
     * The indexes of a table, by name.
     *
     * @return array<string, string>
     */
    private function indexesOf(string $table): array
    {
        /** @var list<object{indexname: string, indexdef: string}> $rows */
        $rows = DB::select(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
            [$table],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->indexname] = $row->indexdef;
        }

        return $indexes;
    }
}
