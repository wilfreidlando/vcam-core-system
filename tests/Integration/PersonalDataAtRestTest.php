<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0123 — what the platform promises about the personal data of the
 * clients: it is stored **in clear**, and the Core keeps no key of its
 * own for it.
 *
 * The client's identity is written through the real repository — the
 * use case, the domain, the Eloquent adapter — so what is proved here is
 * the value that reaches the disk, not a probe table built to measure an
 * encryption that no longer exists.
 *
 * This test lives in the application shell, not in `packages/support`:
 * the shared kernel holds no persistence (G3d), so it may not touch the
 * database even from its own tests.
 */
final class PersonalDataAtRestTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '55555555-5555-4555-8555-555555555555';

    #[Test]
    public function the_identity_of_a_customer_is_stored_in_clear(): void
    {
        $customer = $this->declareCustomer();

        // The row holds the values as they were declared, normalised
        // (ADR-0070 §4): the address is readable, and it is the very
        // address the project declared.
        $row = DB::table('customers')->where('id', $customer->id)->first();

        self::assertNotNull($row);
        self::assertSame('alice@domaine.cm', $row->email);
        self::assertSame('+237690001112', $row->phone);

        // And it is the row itself that carries it: a dump of the table
        // shows the address — that is the whole point of ADR-0123.
        $readable = DB::table('customers')
            ->where('id', $customer->id)
            ->whereRaw('"customers"::text LIKE ?', ['%alice@domaine.cm%'])
            ->exists();

        self::assertTrue($readable, 'the row of the customer holds the address in clear (ADR-0123)');
    }

    #[Test]
    public function no_column_of_the_customers_table_carries_a_ciphertext_or_a_blind_index(): void
    {
        // The tripwire: the encryption must not come back silently
        // (ADR-0123). A `*_ciphertext` or a `*_bidx` column would mean
        // the Core encrypts the client's data again — and that a key of
        // its own would have to be protected all over again.
        /** @var list<object{column_name: string}> $columns */
        $columns = DB::select(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'customers'
        SQL);

        $encrypted = [];

        foreach ($columns as $column) {
            if (preg_match('/_(ciphertext|bidx)$/', $column->column_name) === 1) {
                $encrypted[] = $column->column_name;
            }
        }

        self::assertSame([], $encrypted, sprintf(
            'The Core encrypts nothing of the client any more (ADR-0123): the column(s) %s must not exist.',
            implode(', ', $encrypted),
        ));
    }

    #[Test]
    public function the_core_stores_the_identity_without_any_key_of_its_own(): void
    {
        // There is nothing to configure, nothing to rotate, nothing to
        // lose: the key ring of ADR-0083 §2 is gone with the encryption
        // it protected.
        self::assertFalse(config()->has('privacy.encryption_keys'));
        self::assertFalse(config()->has('privacy.blind_index_key'));

        $customer = $this->declareCustomer();

        $row = DB::table('customers')->where('id', $customer->id)->first();

        self::assertNotNull($row);
        self::assertSame('alice@domaine.cm', $row->email);
    }

    private function declareCustomer(): CustomerView
    {
        return app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'alice',
            displayName: 'Alice Domaine',
            email: 'Alice@Domaine.cm',
            phone: '+237 6 90 00 11 12',
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:personal-data',
            correlationId: null,
        ));
    }
}
