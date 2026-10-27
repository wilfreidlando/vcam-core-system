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
 * Fitness function 4b — the customer is a **declared, minimal, encrypted**
 * copy (ADR-0070, ADR-0083).
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
 *  2. **The personal values are encrypted.** The email and the phone are
 *     stored as ciphertext plus a blind index — never in clear. A plain
 *     `email` column, or a ciphertext column that is not text, fails the
 *     build: it is the difference between a database dump being useless
 *     and a database dump being a breach.
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
        'email_ciphertext',
        'email_bidx',
        'phone_ciphertext',
        'phone_bidx',
        'locale',
        'metadata',
        'status',
        'version',
        'created_at',
        'updated_at',
        'erased_at',
    ];

    /**
     * The columns that carry a personal value, and how they must be
     * stored: the value encrypted, the search by an index that reveals
     * nothing.
     *
     * @var array<string, string>
     */
    /**
     * The four columns that hold a personal value (ADR-0070 §1) and the
     * type each one must have.
     *
     * ADR-0070 §1 declares them `text` — the ciphertext is long and
     * variable, the blind index is a 64-character digest stored in a
     * `text` column like every other indexed text of the Core. The type
     * check is a tripwire against a **clear** column appearing beside
     * them; the proof that the values are really encrypted is the
     * integration test (`packages/billing/tests/Integration/
     * BillingFlowTest.php`), which reads the row back.
     */
    private const PERSONAL_COLUMNS = [
        'email_ciphertext' => 'text',
        'email_bidx' => 'text',
        'phone_ciphertext' => 'text',
        'phone_bidx' => 'text',
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
    public function the_personal_values_of_a_customer_are_encrypted_at_rest(): void
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

        // A clear column would defeat the encryption entirely: it would
        // hold the same value, readable, next to the ciphertext.
        foreach (array_keys($columns) as $column) {
            if (preg_match('/^(email|phone|name|full_name)$/', $column) === 1) {
                $problems[] = sprintf('`%s` holds a personal value in clear', $column);
            }
        }

        self::assertSame(
            [],
            $problems,
            sprintf(
                "The customer's personal values must be encrypted (ADR-0083):\n- %s\n".
                'An email or a phone number stored in clear is a breach waiting for the first dump.',
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
