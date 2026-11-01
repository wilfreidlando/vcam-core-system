<?php

declare(strict_types=1);

namespace Tests\Invariants;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 13 — financial isolation (ADR-0076 §5, ADR-0081 S-02):
 * money never moves between two projects. Written straight in SQL, past
 * every application check, to prove the database itself refuses — the
 * same reasoning as the balance and immutability triggers (G11).
 *
 * No RefreshDatabase, like LedgerInvariantsTest: the probe writes nothing
 * that survives (its transaction is rolled back by the refusal), and the
 * accounts it resolves are scoped to probe-only project ids.
 */
#[Group('invariants')]
final class LedgerProjectIsolationTest extends TestCase
{
    private const PROJECT_A = '01a0a1cc-0000-7000-8000-0000000013a1';

    private const PROJECT_B = '01a0a1cc-0000-7000-8000-0000000013b2';

    #[Test]
    public function an_entry_can_never_cross_projects(): void
    {
        $accounts = app(AccountResolver::class);
        $own = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_A, 'revenue', '', 'XAF'));
        $foreign = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_B, 'clearing', '', 'XAF'));
        $transactionId = (string) Str::uuid();

        $threw = null;

        try {
            // A BALANCED pair — debit project B, credit project A — so the
            // balance trigger (G11) has nothing to object to: only the
            // isolation trigger can refuse it.
            DB::transaction(function () use ($transactionId, $own, $foreign): void {
                DB::table('ledger_transactions')->insert([
                    'id' => $transactionId,
                    'project_id' => self::PROJECT_A,
                    'type' => 'guard_probe',
                    'idempotency_key' => (string) Str::uuid(),
                ]);
                DB::table('ledger_entries')->insert([
                    ['transaction_id' => $transactionId, 'account_id' => $foreign->id, 'direction' => 'debit', 'amount_minor' => 100, 'currency' => 'XAF'],
                    ['transaction_id' => $transactionId, 'account_id' => $own->id, 'direction' => 'credit', 'amount_minor' => 100, 'currency' => 'XAF'],
                ]);
            });
        } catch (QueryException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'An entry on another project\'s account must be rejected by the database (G13).');
        self::assertStringContainsString('crosses projects', $threw->getMessage());
        self::assertSame(0, DB::table('ledger_transactions')->where('id', $transactionId)->count());
    }
}
