<?php

declare(strict_types=1);

namespace Core\Ledger\Tests\Integration;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerAdjustments;
use Core\Ledger\Contracts\LedgerAdjustmentView;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ProposeLedgerAdjustmentCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Ledger\Domain\Exception\LedgerAdjustmentNotFound;
use Core\Ledger\Domain\Exception\LedgerAdjustmentReversalRefused;
use Core\Ledger\Domain\Exception\LedgerAdjustmentTransitionRefused;
use Core\Support\Access\AccessScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0076 §6 — the operating corrections: four eyes, a balanced
 * transaction, an idempotent approval, and "not found" outside the
 * caller's scope.
 */
final class LedgerAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000076d1';

    private const OTHER_PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000076d2';

    private const AUTHOR = '0198a1cc-0000-7000-8000-00000000aaa1';

    private const VALIDATOR = '0198a1cc-0000-7000-8000-00000000bbb2';

    #[Test]
    public function a_correction_waits_for_a_second_pair_of_eyes_and_is_not_booked_yet(): void
    {
        $adjustment = $this->propose();

        self::assertSame('proposed', $adjustment->status);
        self::assertNull($adjustment->ledgerTransactionId);
        self::assertSame(0, $this->transactions());
    }

    #[Test]
    public function the_author_can_never_approve_their_own_correction(): void
    {
        $adjustment = $this->propose();

        $this->expectException(LedgerAdjustmentTransitionRefused::class);

        try {
            app(LedgerAdjustments::class)->approve($adjustment->id, self::AUTHOR, $this->scope());
        } finally {
            // Nothing was booked, and the correction still waits.
            self::assertSame(0, $this->transactions());
            self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $adjustment->id)->value('status'));
        }
    }

    #[Test]
    public function a_second_person_approves_and_the_correction_becomes_a_balanced_ledger_transaction(): void
    {
        $adjustment = $this->propose();

        $approved = app(LedgerAdjustments::class)->approve($adjustment->id, self::VALIDATOR, $this->scope());

        self::assertSame('approved', $approved->status);
        self::assertSame(self::VALIDATOR, $approved->approvedBy);
        self::assertNotNull($approved->ledgerTransactionId);

        self::assertSame(1, $this->transactions());

        $transaction = DB::table('ledger_transactions')->where('id', $approved->ledgerTransactionId)->first();
        self::assertNotNull($transaction);
        self::assertSame('adjustment', $transaction->type);
        self::assertSame(self::PROJECT_ID, $transaction->project_id);
        self::assertSame('adjustment:'.$adjustment->id, $transaction->idempotency_key);

        $entries = DB::table('ledger_entries')->where('transaction_id', $approved->ledgerTransactionId)->get();
        self::assertCount(2, $entries);

        $imbalance = 0;
        foreach ($entries as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $imbalance += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }
        self::assertSame(0, $imbalance);

        $debit = $entries->firstWhere('direction', 'debit');
        self::assertNotNull($debit);
        self::assertIsNumeric($debit->amount_minor);
        self::assertSame(2_500, (int) $debit->amount_minor);
    }

    #[Test]
    public function approving_twice_books_the_transaction_once(): void
    {
        $adjustment = $this->propose();

        app(LedgerAdjustments::class)->approve($adjustment->id, self::VALIDATOR, $this->scope());
        app(LedgerAdjustments::class)->approve($adjustment->id, self::VALIDATOR, $this->scope());

        self::assertSame(1, $this->transactions());
    }

    #[Test]
    public function a_rejected_correction_never_reaches_the_ledger(): void
    {
        $adjustment = $this->propose();

        $rejected = app(LedgerAdjustments::class)->reject($adjustment->id, self::VALIDATOR, $this->scope());

        self::assertSame('rejected', $rejected->status);
        self::assertSame(0, $this->transactions());
    }

    #[Test]
    public function a_correction_outside_the_scope_is_not_found(): void
    {
        $adjustment = $this->propose();

        $this->expectException(LedgerAdjustmentNotFound::class);

        try {
            app(LedgerAdjustments::class)->approve($adjustment->id, self::VALIDATOR, AccessScope::forProject(self::OTHER_PROJECT_ID));
        } finally {
            self::assertSame(0, $this->transactions());
        }
    }

    #[Test]
    public function a_correction_cannot_be_proposed_on_a_project_outside_the_scope(): void
    {
        $this->expectException(LedgerAdjustmentNotFound::class);

        app(LedgerAdjustments::class)->propose($this->command(self::OTHER_PROJECT_ID), AccessScope::forProject(self::PROJECT_ID));
    }

    #[Test]
    public function the_motive_is_mandatory(): void
    {
        $this->expectException(LedgerAdjustmentTransitionRefused::class);

        app(LedgerAdjustments::class)->propose(
            $this->command(self::PROJECT_ID, reason: '   '),
            $this->scope(),
        );
    }

    #[Test]
    public function the_amount_must_be_strictly_positive(): void
    {
        $this->expectException(LedgerAdjustmentTransitionRefused::class);

        app(LedgerAdjustments::class)->propose(
            $this->command(self::PROJECT_ID, amountMinor: 0),
            $this->scope(),
        );
    }

    #[Test]
    public function below_the_threshold_the_correction_is_booked_at_once(): void
    {
        config(['ledger.adjustments.approval_threshold_minor' => 10_000]);

        // The proposal never moves money: the trace of the proposal is
        // written first, and the booking is a step of its own.
        $proposed = $this->propose(amountMinor: 1_000);
        self::assertSame('proposed', $proposed->status);
        self::assertSame(0, $this->transactions());

        $adjustment = app(LedgerAdjustments::class)->bookWithoutApproval($proposed->id, $this->scope());

        // Below the threshold, no second pair of eyes is needed: the
        // correction is booked at once, and nobody "approved" it —
        // `approved_by` stays NULL, which the four-eyes constraint of
        // the table allows.
        self::assertSame('approved', $adjustment->status);
        self::assertNull($adjustment->approvedBy);
        self::assertNotNull($adjustment->ledgerTransactionId);
        self::assertSame(1, $this->transactions());
    }

    #[Test]
    public function at_or_above_the_threshold_the_booking_step_leaves_the_correction_waiting(): void
    {
        config(['ledger.adjustments.approval_threshold_minor' => 10_000]);

        $proposed = $this->propose(amountMinor: 10_000);

        $adjustment = app(LedgerAdjustments::class)->bookWithoutApproval($proposed->id, $this->scope());

        // The threshold says a second pair of eyes is needed: the
        // booking step books nothing, and the correction waits.
        self::assertSame('proposed', $adjustment->status);
        self::assertNull($adjustment->ledgerTransactionId);
        self::assertSame(0, $this->transactions());
    }

    #[Test]
    public function at_or_above_the_threshold_a_second_pair_of_eyes_is_needed(): void
    {
        config(['ledger.adjustments.approval_threshold_minor' => 10_000]);

        $adjustment = $this->propose(amountMinor: 10_000);

        self::assertSame('proposed', $adjustment->status);
        self::assertSame(0, $this->transactions());
    }

    #[Test]
    public function the_database_itself_refuses_an_approver_who_is_the_author(): void
    {
        $adjustment = $this->propose();

        $threw = null;

        try {
            DB::table('ledger_adjustments')->where('id', $adjustment->id)->update([
                'status' => 'approved',
                'approved_by' => self::AUTHOR,
            ]);
        } catch (QueryException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the four-eyes constraint to refuse the author as approver.');
        self::assertStringContainsString('ledger_adjustments_four_eyes', $threw->getMessage());
    }

    // ── The correction may never touch a wallet (the `wallet` module's) ──

    #[Test]
    public function a_correction_may_not_debit_the_account_of_a_party(): void
    {
        $walletId = $this->walletWithBalance(7_000);
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $transactionsBefore = DB::table('ledger_transactions')->count();
        $entriesBefore = DB::table('ledger_entries')->count();

        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, sourceKind: 'wallet', sourceReference: $walletId, reversesTransactionId: $reversed),
                $this->scope(),
            );
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: the balance of a party is the wallet module\'s.');
        self::assertStringContainsString('wallet', $threw->getMessage());

        // Nothing was written: neither the ledger nor the balance of the
        // party, and no account of a party was even created.
        self::assertSame($transactionsBefore, DB::table('ledger_transactions')->count());
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
        self::assertSame(0, $this->transactions());
        self::assertSame(0, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'wallet')->count());
        self::assertSame(0, DB::table('ledger_adjustments')->count());
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertIsNumeric($balance);
        self::assertSame(7_000, (int) $balance);
    }

    #[Test]
    public function a_correction_may_not_credit_the_account_of_a_party(): void
    {
        $walletId = $this->walletWithBalance(7_000);
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $transactionsBefore = DB::table('ledger_transactions')->count();
        $entriesBefore = DB::table('ledger_entries')->count();

        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, destinationKind: 'wallet', destinationReference: $walletId, reversesTransactionId: $reversed),
                $this->scope(),
            );
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: the balance of a party is the wallet module\'s.');
        self::assertSame($transactionsBefore, DB::table('ledger_transactions')->count());
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
        self::assertSame(0, $this->transactions());
        self::assertSame(0, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'wallet')->count());
        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertIsNumeric($balance);
        self::assertSame(7_000, (int) $balance);
    }

    // ── A correction moves money between two DIFFERENT accounts ──────────

    #[Test]
    public function a_correction_may_not_move_money_from_an_account_to_itself(): void
    {
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $transactionsBefore = DB::table('ledger_transactions')->count();
        $entriesBefore = DB::table('ledger_entries')->count();

        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, sourceKind: 'clearing', sourceReference: '', destinationKind: 'clearing', destinationReference: '', reversesTransactionId: $reversed),
                $this->scope(),
            );
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: a correction needs two different accounts.');
        self::assertSame($transactionsBefore, DB::table('ledger_transactions')->count());
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
        self::assertSame(0, $this->transactions());
        self::assertSame(0, DB::table('ledger_adjustments')->count());
    }

    // ── ADR-0076 §5 — a correction names the transaction it reverses ────

    #[Test]
    public function the_correction_names_the_transaction_it_reverses(): void
    {
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $adjustment = $this->propose(reversesTransactionId: $reversed);

        self::assertSame($reversed, $adjustment->reversesTransactionId);

        $approved = app(LedgerAdjustments::class)->approve($adjustment->id, self::VALIDATOR, $this->scope());

        // The transaction the correction produced points back at the one
        // it reverses: the column is finally alive.
        $transaction = DB::table('ledger_transactions')->where('id', $approved->ledgerTransactionId)->first();
        self::assertNotNull($transaction);
        self::assertSame($reversed, $transaction->reverses_transaction_id);
    }

    #[Test]
    public function a_correction_may_not_reverse_a_transaction_of_another_project(): void
    {
        $foreign = $this->postTransaction(self::OTHER_PROJECT_ID);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, reversesTransactionId: $foreign),
                $this->scope(),
            );
        } catch (LedgerAdjustmentReversalRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: money never crosses projects.');
        self::assertSame(0, DB::table('ledger_adjustments')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'adjustment')->count());
    }

    #[Test]
    public function a_correction_may_not_reverse_a_transaction_that_does_not_exist(): void
    {
        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, reversesTransactionId: (string) Str::uuid()),
                $this->scope(),
            );
        } catch (LedgerAdjustmentReversalRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: there is nothing to reverse.');
        self::assertSame(0, DB::table('ledger_adjustments')->count());
    }

    #[Test]
    public function a_correction_may_not_reverse_another_correction(): void
    {
        $first = $this->propose();
        $approved = app(LedgerAdjustments::class)->approve($first->id, self::VALIDATOR, $this->scope());

        self::assertNotNull($approved->ledgerTransactionId);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->propose(
                $this->command(self::PROJECT_ID, reversesTransactionId: $approved->ledgerTransactionId),
                $this->scope(),
            );
        } catch (LedgerAdjustmentReversalRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: a correction never corrects another correction.');
        self::assertSame(1, DB::table('ledger_adjustments')->count());
        self::assertSame(1, $this->transactions());
    }

    // ── The invariants belong to the STATE, not to the proposal ──────────

    /**
     * A correction written straight into the table — the way a row
     * written before a rule existed, or by a tool that knows nothing
     * of the domain, would be. The table carries NO CHECK constraint
     * on the account kinds: the database accepts it, and the domain is
     * the only line of defence.
     */
    #[Test]
    public function a_correction_that_carries_a_wallet_account_is_refused_when_it_is_approved(): void
    {
        $walletId = $this->walletWithBalance(7_000);
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $this->insertAdjustment([
            'source_kind' => 'wallet',
            'source_reference' => $walletId,
            'reverses_transaction_id' => $reversed,
        ]);

        // The CONTENT of the database carries the faulty row: the table
        // has no CHECK on the kinds, so nothing stopped the write.
        $row = DB::table('ledger_adjustments')->first();
        self::assertNotNull($row);
        self::assertIsString($row->id);
        self::assertSame('wallet', $row->source_kind);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->approve($row->id, self::VALIDATOR, $this->scope());
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: the balance of a party is the wallet module\'s.');
        self::assertStringContainsString('wallet', $threw->getMessage());

        // Nothing was written to the ledger, no account of a party was
        // even created, and the balance of the party never moved.
        self::assertSame(0, $this->transactions());
        self::assertSame(0, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'wallet')->count());
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $row->id)->value('status'));

        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertIsNumeric($balance);
        self::assertSame(7_000, (int) $balance);
    }

    #[Test]
    public function a_correction_that_moves_money_from_an_account_to_itself_is_refused_when_it_is_approved(): void
    {
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $entriesBefore = DB::table('ledger_entries')->count();

        $this->insertAdjustment([
            'source_kind' => 'clearing',
            'source_reference' => 'same-account',
            'destination_kind' => 'clearing',
            'destination_reference' => 'same-account',
            'reverses_transaction_id' => $reversed,
        ]);

        $row = DB::table('ledger_adjustments')->first();
        self::assertNotNull($row);
        self::assertIsString($row->id);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->approve($row->id, self::VALIDATOR, $this->scope());
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: a correction needs two different accounts.');
        self::assertSame(0, $this->transactions());
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $row->id)->value('status'));
    }

    #[Test]
    public function a_correction_that_names_no_transaction_to_counter_pass_is_refused_when_it_is_approved(): void
    {
        $entriesBefore = DB::table('ledger_entries')->count();

        $this->insertAdjustment(['reverses_transaction_id' => null]);

        $row = DB::table('ledger_adjustments')->first();
        self::assertNotNull($row);
        self::assertIsString($row->id);
        self::assertNull($row->reverses_transaction_id);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->approve($row->id, self::VALIDATOR, $this->scope());
        } catch (LedgerAdjustmentReversalRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: a correction that names no transaction corrects nothing.');
        self::assertSame(0, $this->transactions());
        self::assertSame($entriesBefore, DB::table('ledger_entries')->count());
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $row->id)->value('status'));
    }

    /**
     * The path that books WITHOUT a second pair of eyes is a path that
     * moves money too: the invariant is checked there as well.
     */
    #[Test]
    public function the_invariants_are_refused_on_the_path_that_books_without_a_second_pair_of_eyes(): void
    {
        config(['ledger.adjustments.approval_threshold_minor' => 10_000]);

        $walletId = $this->walletWithBalance(7_000);
        $reversed = $this->postTransaction(self::PROJECT_ID);

        $this->insertAdjustment([
            'source_kind' => 'wallet',
            'source_reference' => $walletId,
            'amount_minor' => 1_000,
            'reverses_transaction_id' => $reversed,
        ]);

        $row = DB::table('ledger_adjustments')->first();
        self::assertNotNull($row);
        self::assertIsString($row->id);

        $threw = null;

        try {
            app(LedgerAdjustments::class)->bookWithoutApproval($row->id, $this->scope());
        } catch (LedgerAdjustmentTransitionRefused $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'Expected the correction to be refused: the balance of a party is the wallet module\'s.');
        self::assertSame(0, $this->transactions());
        self::assertSame(0, DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->where('kind', 'wallet')->count());
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $row->id)->value('status'));

        $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertIsNumeric($balance);
        self::assertSame(7_000, (int) $balance);
    }

    /**
     * A correction written straight into the table, the way a row
     * written before a rule existed would be. The table carries no
     * CHECK constraint on the account kinds: the domain is the only
     * line of defence.
     *
     * @param array<string, mixed> $attributes
     */
    private function insertAdjustment(array $attributes): void
    {
        DB::table('ledger_adjustments')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'project_id' => self::PROJECT_ID,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Écriture posée directement en base, hors proposition.',
            'status' => 'proposed',
            'proposed_by' => self::AUTHOR,
            'approved_by' => null,
            'ledger_transaction_id' => null,
            'reverses_transaction_id' => null,
            'created_at' => now(),
            'approved_at' => null,
        ], $attributes));
    }

    private function propose(
        int $amountMinor = 2_500,
        ?string $reversesTransactionId = null,
    ): LedgerAdjustmentView {
        return app(LedgerAdjustments::class)->propose(
            $this->command(self::PROJECT_ID, amountMinor: $amountMinor, reversesTransactionId: $reversesTransactionId),
            $this->scope(),
        );
    }

    private function command(
        string $projectId,
        int $amountMinor = 2_500,
        string $reason = 'Reclassement d\'un encaissement reçu sans facture.',
        string $sourceKind = 'clearing',
        string $sourceReference = '',
        string $destinationKind = 'revenue',
        string $destinationReference = '',
        ?string $reversesTransactionId = null,
    ): ProposeLedgerAdjustmentCommand {
        return new ProposeLedgerAdjustmentCommand(
            projectId: $projectId,
            sourceKind: $sourceKind,
            sourceReference: $sourceReference,
            destinationKind: $destinationKind,
            destinationReference: $destinationReference,
            amountMinor: $amountMinor,
            currency: 'XAF',
            reason: $reason,
            proposedBy: self::AUTHOR,
            reversesTransactionId: $reversesTransactionId ?? $this->postTransaction($projectId),
        );
    }

    private function scope(): AccessScope
    {
        return AccessScope::forProject(self::PROJECT_ID);
    }

    private function transactions(): int
    {
        return DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'adjustment')
            ->count();
    }

    /**
     * A settled transaction of the project — the one a correction
     * counter-passes.
     */
    private function postTransaction(string $projectId, string $type = 'payment_settlement'): string
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'revenue', '', 'XAF'));

        $ref = app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            projectId: $projectId,
            type: $type,
            externalRef: null,
            idempotencyKey: 'test:'.Str::uuid(),
            entries: [
                new LedgerEntryInput($clearing->id, Direction::Debit, 1_000, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, 1_000, 'XAF'),
            ],
        ));

        return $ref->id;
    }

    /**
     * A wallet of the project, with its balance — the row a correction
     * must never touch.
     */
    private function walletWithBalance(int $availableMinor): string
    {
        $walletId = (string) Str::uuid();
        $now = now();

        DB::table('wallets')->insert([
            'id' => $walletId,
            'project_id' => self::PROJECT_ID,
            'party_id' => (string) Str::uuid(),
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => $now,
        ]);

        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletId,
            'available_minor' => $availableMinor,
            'held_minor' => 0,
            'version' => 1,
            'updated_at' => $now,
        ]);

        return $walletId;
    }
}
