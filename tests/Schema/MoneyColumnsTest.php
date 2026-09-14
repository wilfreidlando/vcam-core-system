<?php

declare(strict_types=1);

namespace Tests\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 5 — money is stored as integer minor units, never as a
 * floating-point or arbitrary-precision decimal column.
 *
 * The failure mode is silent and unrecoverable: 0.1 + 0.2 !== 0.3 in binary
 * floating point, so a float column turns rounding error into money that does
 * not reconcile. By the time anyone notices, the wrong numbers have been paid
 * out and the audit trail records them as correct.
 *
 * XAF has no sub-unit at all (exponent 0) and USD has two, which is precisely
 * what `amount_minor bigint` + `currency char(3)` expresses cleanly.
 *
 * WHY SCHEMA INTROSPECTION RATHER THAN PARSING MIGRATIONS (ADR-0023): migrations
 * are code. They use raw `DB::statement`, `->change()`, macros and traits — the
 * api_keys migration in this very repository adds a column through raw SQL. A
 * static parser sees none of that. The migrated schema is the ground truth, so
 * that is what we inspect.
 */
#[Group('schema')]
final class MoneyColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Inexact or unsuitable types for money. `numeric`/`decimal` are exact but
     * still rejected: allowing two representations of money guarantees that one
     * day the two will disagree.
     */
    private const FORBIDDEN_TYPES = [
        'double precision',
        'real',
        'money',
        'numeric',
        'decimal',
        'float',
    ];

    /**
     * A column whose name says it holds money must be bigint.
     *
     * Deliberately anchored on whole words: `job_batches.total_jobs` is a count,
     * not an amount. The Core's own convention is a `_minor` suffix
     * (amount_minor, available_minor, held_minor), which this also covers.
     */
    private const MONEY_NAME_PATTERN = '/(^|_)(amount|balance|fee|fees|price|commission|debit|credit|minor)(_|$)/i';

    /**
     * Tables owned by Laravel, not by the Core. Their shape is the framework's
     * business; the guardrail governs what we design.
     */
    private const FRAMEWORK_TABLES = [
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'users',
        'password_reset_tokens',
        'oauth_auth_codes',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_clients',
        'oauth_device_codes',
        'personal_access_tokens',
    ];

    #[Test]
    public function no_table_stores_money_in_an_inexact_column_type(): void
    {
        $offenders = [];

        foreach ($this->columns() as $column) {
            if (in_array(strtolower($column->data_type), self::FORBIDDEN_TYPES, true)) {
                $offenders[] = sprintf(
                    '%s.%s is %s',
                    $column->table_name,
                    $column->column_name,
                    $column->data_type,
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Money must be stored as integer minor units (bigint) with a currency code.\n".
            "Offending columns:\n  - ".implode("\n  - ", $offenders)."\n\n".
            'Use bigint + char(3) currency. See docs/architecture/conventions-donnees.md.'
        );
    }

    #[Test]
    public function every_monetary_column_is_a_bigint(): void
    {
        $offenders = [];

        foreach ($this->columns() as $column) {
            if (preg_match(self::MONEY_NAME_PATTERN, $column->column_name) !== 1) {
                continue;
            }

            if ($column->data_type === 'bigint') {
                continue;
            }

            $offenders[] = sprintf(
                '%s.%s is %s',
                $column->table_name,
                $column->column_name,
                $column->data_type,
            );
        }

        self::assertSame(
            [],
            $offenders,
            "A column named after a monetary concept must be bigint minor units.\n".
            "Offending columns:\n  - ".implode("\n  - ", $offenders)."\n\n".
            'If the column is not monetary, rename it so the guardrail is not ambiguous.'
        );
    }

    /**
     * @return list<object{table_name: string, column_name: string, data_type: string}>
     */
    private function columns(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::FRAMEWORK_TABLES), '?'));

        /** @var list<object{table_name: string, column_name: string, data_type: string}> $rows */
        $rows = DB::select(<<<SQL
            SELECT c.table_name, c.column_name, c.data_type
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_name = c.table_name
             AND t.table_schema = c.table_schema
            WHERE c.table_schema = 'public'
              AND t.table_type = 'BASE TABLE'
              AND c.table_name NOT IN ($placeholders)
            ORDER BY c.table_name, c.ordinal_position
        SQL, self::FRAMEWORK_TABLES);

        return $rows;
    }
}
