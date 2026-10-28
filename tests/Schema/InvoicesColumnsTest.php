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
 * G4c — the invoice tables hold no column outside their white-list, and
 * the personal values they carry are encrypted at rest.
 *
 * The white-list is the same discipline as the customers' (ADR-0070 §1,
 * G4b): every column here widens the Core's data and compliance
 * surface, so each one is a decision — the list is the record of those
 * decisions, and a column that appears without being argued for fails
 * the build.
 *
 * The introspection reads `information_schema` (ADR-0023): the migrated
 * schema is the ground truth, not the migrations themselves.
 */
#[Group('schema')]
final class InvoicesColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The complete permitted shape of `invoices` (ADR-0073 §1,
     * ADR-0094 §§1-3). An addition must be argued for and recorded.
     */
    private const ALLOWED_COLUMNS = [
        'id', 'project_id', 'customer_id', 'subscription_id', 'period_id', 'number', 'status', 'currency',
        'subtotal_minor', 'tax_minor', 'total_minor', 'amount_paid_minor', 'customer_snapshot',
        'due_at', 'issued_at', 'paid_at', 'voided_at', 'void_reason', 'ledger_transaction_id', 'version',
        'created_at', 'updated_at',
    ];

    /**
     * The complete permitted shape of `invoice_lines`.
     */
    private const ALLOWED_LINE_COLUMNS = [
        'id', 'invoice_id', 'project_id', 'kind', 'description', 'quantity', 'unit_amount_minor', 'amount_minor',
        'tax_rate_bps', 'tax_amount_minor', 'period_start', 'period_end', 'created_at',
    ];

    /**
     * The complete permitted shape of `invoice_number_sequences`.
     */
    private const ALLOWED_SEQUENCE_COLUMNS = [
        'project_id', 'year', 'next_value', 'updated_at',
    ];

    /**
     * The complete permitted shape of `invoice_payments` — the attempts
     * to collect an invoice online (ADR-0073 §2).
     */
    private const ALLOWED_PAYMENT_COLUMNS = [
        'id', 'invoice_id', 'project_id', 'payment_id', 'amount_minor', 'currency', 'status',
        'confirmed_amount_minor', 'failure_reason', 'settled_at', 'ledger_transaction_id',
        'created_at', 'updated_at',
    ];

    /**
     * The complete permitted shape of `manual_receipts` — the offline
     * collection (ADR-0073 §4, ADR-0095).
     */
    private const ALLOWED_RECEIPT_COLUMNS = [
        'id', 'project_id', 'invoice_id', 'method', 'reference', 'amount_minor', 'currency',
        'received_at', 'recorded_by', 'approved_by', 'approved_at', 'status', 'evidence_ref',
        'note', 'ledger_transaction_id', 'created_at', 'updated_at',
    ];

    /**
     * The columns that hold a money amount: they are `bigint` minor
     * units, never a float (G5). A pair, not a map: the same column
     * name lives on several tables.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const MONEY_COLUMNS = [
        ['subtotal_minor', 'invoices'],
        ['tax_minor', 'invoices'],
        ['total_minor', 'invoices'],
        ['amount_paid_minor', 'invoices'],
        ['unit_amount_minor', 'invoice_lines'],
        ['amount_minor', 'invoice_lines'],
        ['tax_amount_minor', 'invoice_lines'],
        ['amount_minor', 'invoice_payments'],
        ['confirmed_amount_minor', 'invoice_payments'],
        ['amount_minor', 'manual_receipts'],
    ];

    #[Test]
    public function the_invoice_tables_have_no_unexpected_column(): void
    {
        if (! Schema::hasTable('invoices')) {
            self::markTestSkipped('The invoices table does not exist yet (Lot 11).');
        }

        $tables = [
            'invoices' => self::ALLOWED_COLUMNS,
            'invoice_lines' => self::ALLOWED_LINE_COLUMNS,
            'invoice_number_sequences' => self::ALLOWED_SEQUENCE_COLUMNS,
            'invoice_payments' => self::ALLOWED_PAYMENT_COLUMNS,
            'manual_receipts' => self::ALLOWED_RECEIPT_COLUMNS,
        ];

        foreach ($tables as $table => $allowed) {
            $unexpected = array_values(array_diff(array_keys($this->columns($table)), $allowed));

            self::assertSame([], $unexpected, sprintf(
                "Unexpected column(s) on `%s`: %s.\n".
                "Every column here widens the Core's data and compliance surface, so each one is a\n".
                'decision: add it to the white-list with an ADR, or keep the data in the product.',
                $table,
                implode(', ', $unexpected),
            ));
        }
    }

    #[Test]
    public function the_money_columns_of_an_invoice_are_bigint_minor_units(): void
    {
        if (! Schema::hasTable('invoices')) {
            self::markTestSkipped('The invoices table does not exist yet (Lot 11).');
        }

        foreach (self::MONEY_COLUMNS as [$column, $table]) {
            $columns = $this->columns($table);

            self::assertArrayHasKey($column, $columns, sprintf('`%s.%s` is missing.', $table, $column));
            self::assertSame('bigint', $columns[$column], sprintf(
                '`%s.%s` must be a bigint of minor units, never a float (G5).',
                $table,
                $column,
            ));
        }
    }

    #[Test]
    public function no_column_of_an_invoice_holds_a_personal_value_in_clear(): void
    {
        if (! Schema::hasTable('invoices')) {
            self::markTestSkipped('The invoices table does not exist yet (Lot 11).');
        }

        $problems = [];

        foreach (['invoices', 'invoice_lines', 'invoice_payments', 'manual_receipts'] as $table) {
            foreach (array_keys($this->columns($table)) as $column) {
                if (preg_match('/^(email|phone|name|display_name|full_name|first_name|last_name)$/', $column) === 1) {
                    $problems[] = sprintf('`%s.%s` holds a personal value in clear', $table, $column);
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function the_customer_frozen_on_an_invoice_is_encrypted(): void
    {
        if (! Schema::hasTable('invoices')) {
            self::markTestSkipped('The invoices table does not exist yet (Lot 11).');
        }

        // ADR-0083 §2 / ADR-0094 §2 — the personal values of the
        // snapshot are ciphertexts, versioned by the cipher.
        $row = DB::table('invoices')->first();

        if ($row === null) {
            self::markTestSkipped('No invoice to read.');
        }

        $raw = $row->customer_snapshot;
        self::assertIsString($raw);

        $snapshot = json_decode($raw, true);

        self::assertIsArray($snapshot);

        foreach (['display_name_ciphertext', 'email_ciphertext', 'phone_ciphertext'] as $key) {
            if (! isset($snapshot[$key])) {
                continue;
            }

            $ciphertext = $snapshot[$key];
            self::assertIsString($ciphertext);
            self::assertStringStartsWith('pii:v1:', $ciphertext, sprintf(
                '`%s` must hold a versioned ciphertext, never a clear value (ADR-0083 §2).',
                $key,
            ));
        }
    }

    /**
     * The columns of a table, read from the migrated schema (ADR-0023).
     *
     * @return array<string, string> the column name => its data type
     */
    private function columns(string $table): array
    {
        /** @var list<object{column_name: string, data_type: string}> $rows */
        $rows = DB::select(
            'SELECT column_name, data_type FROM information_schema.columns'
            .' WHERE table_schema = \'public\' AND table_name = ? ORDER BY ordinal_position',
            [$table],
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->column_name] = $row->data_type;
        }

        return $columns;
    }
}
