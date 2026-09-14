<?php

declare(strict_types=1);

namespace Tests\Invariants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 11 — the four financial invariants.
 *
 * These are the assertions that make the ledger trustworthy. They are written
 * now, before the ledger exists, so that Lot 2 is developed against them rather
 * than having them retrofitted around whatever it happened to build.
 *
 * Each test below skips explicitly while the tables are absent — never silently
 * passes. Owner: the Money developer (docs/gouvernance/definition-of-done.md,
 * "Definition of Done spécifique Money").
 *
 * @see docs/architecture/garde-fous-inactifs.md (G11)
 */
#[Group('invariants')]
final class LedgerInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Double-entry bookkeeping in one sentence: every transaction moves value
     * from somewhere to somewhere else, so its entries must cancel out. A
     * transaction that does not sum to zero means value appeared or vanished —
     * the ledger has stopped describing reality and no report derived from it
     * can be trusted.
     */
    #[Test]
    public function every_transaction_balances_to_zero(): void
    {
        self::markTestIncomplete('Implemented in Lot 2 together with the ledger.');
    }

    /**
     * The materialised balance is a cache of the entries, and a cache that can
     * disagree with its source is a liability. If they diverge, the platform
     * will happily authorise a payout against money that is not there.
     */
    #[Test]
    public function the_materialised_balance_equals_the_sum_of_its_entries(): void
    {
        self::markTestIncomplete('Implemented in Lot 2 together with wallet balances.');
    }

    /**
     * Two simultaneous debits on the same wallet must serialise. The failure is
     * the classic one: both read a balance of 100, both authorise 80, and the
     * wallet ends at -60. Pessimistic row locking (SELECT ... FOR UPDATE) is the
     * chosen defence (ADR-0006), and this test is what proves it holds under
     * real concurrency rather than in theory.
     */
    #[Test]
    public function concurrent_debits_never_produce_a_negative_balance(): void
    {
        self::markTestIncomplete(
            'Implemented in Lot 2: parallel processes against real PostgreSQL row locks.'
        );
    }

    /**
     * Replaying an identical request must be a no-op that returns the original
     * outcome — one ledger transaction, one outbox event, the same response
     * body. This is what makes provider retries and queue redeliveries safe.
     */
    #[Test]
    public function replaying_an_identical_operation_has_no_second_effect(): void
    {
        self::markTestIncomplete('Implemented in Lot 2 together with the idempotency store.');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('ledger_entries')) {
            self::markTestSkipped(
                'The ledger does not exist yet (Lot 2, G11). '.
                'These invariants are the Definition of Done for the Money lot.'
            );
        }
    }
}
