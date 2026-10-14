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
 * Fitness function 4 — the Core never becomes a directory of end users.
 *
 * A `Party` is a deliberately thin reference: the project it belongs to, the
 * product's own identifier for the actor (`external_ref`), and a type. No name,
 * no email, no phone number.
 *
 * Two reasons, and the second is the one people forget:
 *
 *  1. Volume. A tourism platform with 500,000 users of whom 3,000 actually
 *     transact creates 3,000 parties, not 500,000. There is no directory to
 *     synchronise and nothing to keep in step.
 *  2. Compliance surface. Personal data the Core never stores is personal data
 *     the Core cannot leak, cannot be asked to erase, and does not have to
 *     account for. Copying a profile "just for convenience" quietly moves the
 *     whole platform into scope.
 *
 * The temptation is real and arrives as a small, reasonable request — "just the
 * phone number, for the payment prompt". Hence a build failure rather than a
 * convention.
 */
#[Group('schema')]
final class PartiesPiiTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_COLUMN_PATTERN = '/(e?mail|name|phone|msisdn|mobile|pass(word)?|address|birth|dob|gender|ssn|nin|id_card|passport|avatar|photo|ip_addr)/i';

    /**
     * The complete permitted shape of `parties`. An addition must be argued for
     * and recorded, not slipped in.
     */
    private const ALLOWED_COLUMNS = [
        'id',
        'project_id',
        'external_ref',
        'type',
        'kyc_status',
        'metadata',
        // ADR-0065 — `live` or `sandbox`: isolation, not a personal datum.
        'environment',
        'created_at',
        'updated_at',
    ];

    #[Test]
    public function the_parties_table_holds_no_personally_identifying_column(): void
    {
        if (! Schema::hasTable('parties')) {
            self::markTestSkipped(
                'The parties table does not exist yet (Lot 2). '.
                'Registered in docs/architecture/garde-fous-inactifs.md; this guard activates with the migration.'
            );
        }

        $offenders = array_values(array_filter(
            $this->columnNames(),
            static fn (string $column): bool => preg_match(self::FORBIDDEN_COLUMN_PATTERN, $column) === 1,
        ));

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "The Core must not store end-user identity. Forbidden columns on `parties`: %s.\n".
                "A Party is a reference (project_id + external_ref + type), not a profile.\n".
                'The product owns its users; the Core only needs to know which actor a wallet or payout belongs to.',
                implode(', ', $offenders),
            ),
        );
    }

    #[Test]
    public function the_parties_table_has_no_unexpected_column(): void
    {
        if (! Schema::hasTable('parties')) {
            self::markTestSkipped('The parties table does not exist yet (Lot 2).');
        }

        $unexpected = array_values(array_diff($this->columnNames(), self::ALLOWED_COLUMNS));

        self::assertSame(
            [],
            $unexpected,
            sprintf(
                "Unexpected column(s) on `parties`: %s.\n".
                "Every column here widens the Core's data and compliance surface, so each one is a\n".
                'decision: add it to ALLOWED_COLUMNS with an ADR, or keep the data in the product.',
                implode(', ', $unexpected),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function columnNames(): array
    {
        /** @var list<object{column_name: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'parties'
            ORDER BY ordinal_position
        SQL);

        return array_map(static fn (object $row): string => $row->column_name, $rows);
    }
}
