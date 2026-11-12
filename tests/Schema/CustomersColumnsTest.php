<?php

declare(strict_types=1);

namespace Tests\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 4b — the customer is a **declared, minimal** copy of
 * the identity the project gave (ADR-0070, ADR-0083, ADR-0123).
 *
 * `parties` may hold no personal data at all (G4): a party is a reference.
 * A `customer` is something else — the Core does hold an identity for the
 * people who pay a project. That is a deliberate widening of the
 * compliance surface, and it is exactly why it is pinned:
 *
 *  1. **The shape is a white-list.** Every column of `customers` is
 *     listed below. A new column is a decision — it needs an ADR, not a
 *     migration. The temptation to add "just one more" is the whole
 *     point of the list.
 *  2. **The personal values are stored in clear.** ADR-0123: the Core
 *     encrypts nothing of the client's data any more — the address and
 *     the number are plain `text` columns, and no key of its own guards
 *     them. What the Core still guards is their **exposure** (the console
 *     masks them, ADR-0083 §3) and their **erasure** (ADR-0083 §6). The
 *     tripwire below makes sure the encryption does not come back
 *     silently.
 *
 * The two assertions below are what make ADR-0070 §Conséquences real
 * instead of aspirational.
 */
#[Group('schema')]
final class CustomersColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The complete permitted shape of `customers` (ADR-0070 §1). An
     * addition must be argued for and recorded.
     */
    private const ALLOWED_COLUMNS = [
        'id',
        'project_id',
        'external_ref',
        'display_name',
        'email',
        'phone',
        'locale',
        'metadata',
        'status',
        'version',
        'created_at',
        'updated_at',
        'erased_at',
    ];

    /**
     * The two columns that hold a personal value, and how they must be
     * stored: **in clear**, as plain text (ADR-0123).
     *
     * ADR-0070 §1 declares them `text` — the address and the number are
     * short and variable. The type check is a tripwire against the
     * encrypted columns coming back beside them; the proof that the
     * values are really stored in clear is the integration test
     * (`packages/billing/tests/Integration/BillingFlowTest.php`), which
     * reads the row back.
     *
     * @var array<string, string>
     */
    private const PERSONAL_COLUMNS = [
        'email' => 'text',
        'phone' => 'text',
    ];

    #[Test]
    public function the_customers_table_has_no_unexpected_column(): void
    {
        if (! Schema::hasTable('customers')) {
            self::markTestSkipped(
                'The customers table does not exist yet (Lot 10). '.
                'Registered in docs/architecture/garde-fous-inactifs.md; this guard activates with the migration.'
            );
        }

        $unexpected = array_values(array_diff($this->columnNames(), self::ALLOWED_COLUMNS));

        self::assertSame(
            [],
            $unexpected,
            sprintf(
                "Unexpected column(s) on `customers`: %s.\n".
                "Every column here widens the Core's data and compliance surface, so each one is a\n".
                'decision: add it to ALLOWED_COLUMNS with an ADR, or keep the data in the product.',
                implode(', ', $unexpected),
            ),
        );
    }

    #[Test]
    public function the_personal_values_of_a_customer_are_stored_in_clear_and_no_ciphertext_column_remains(): void
    {
        if (! Schema::hasTable('customers')) {
            self::markTestSkipped('The customers table does not exist yet (Lot 10).');
        }

        $columns = $this->columns();
        $problems = [];

        foreach (self::PERSONAL_COLUMNS as $column => $expectedType) {
            if (! array_key_exists($column, $columns)) {
                $problems[] = sprintf('%s is missing', $column);

                continue;
            }

            if ($columns[$column] !== $expectedType) {
                $problems[] = sprintf('%s is `%s`, expected `%s`', $column, $columns[$column], $expectedType);
            }
        }

        // ADR-0123 — the tripwire: the encryption must not come back
        // silently. A `*_ciphertext` or a `*_bidx` column would mean the
        // Core encrypts the client's data again — and that a key of its
        // own would have to be protected all over again.
        foreach (array_keys($columns) as $column) {
            if (preg_match('/_(ciphertext|bidx)$/', $column) === 1) {
                $problems[] = sprintf('`%s` is an encrypted column: the Core encrypts nothing of the client any more (ADR-0123)', $column);
            }
        }

        self::assertSame(
            [],
            $problems,
            sprintf(
                "The customer's personal values are stored in clear (ADR-0123):\n- %s\n".
                "The Core keeps no key of its own for them: an encrypted column beside them is a decision\n".
                'to take again, with an ADR.',
                implode("\n- ", $problems),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function columnNames(): array
    {
        return array_keys($this->columns());
    }

    /**
     * @return array<string, string> the column name → its PostgreSQL type
     */
    private function columns(): array
    {
        /** @var list<object{column_name: string, data_type: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'customers'
            ORDER BY ordinal_position
        SQL);

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->column_name] = $row->data_type;
        }

        return $columns;
    }
}
