<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Console;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0076 §2 — « Une écriture de reclassement unique, motivée et
 * auditée, solde l'ancien compte vers le nouveau au déploiement. »
 *
 * The command moves the money of the historical project-level `clearing`
 * account to the provider's own account — once, never by guessing
 * between two providers, and only for the part of the balance that is
 * identifiable as the provider's own money.
 */
final class ReclassifyClearingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000000c3';

    private const REASON = 'Reclassement du solde historique de my-coolpay lors du déploiement ADR-0076.';

    #[Test]
    public function it_refuses_to_run_without_a_motive(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(5_000);

        self::assertNotSame(0, $this->runCommand(null));

        self::assertStringContainsString('motif', Artisan::output());
        self::assertSame(5_000, $this->clearingBalance(''), 'the balance must be left intact');
        self::assertSame(0, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    #[Test]
    public function it_moves_the_historical_balance_to_the_providers_account_and_never_twice(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(5_000);

        self::assertSame(0, $this->runCommand());

        self::assertSame(0, $this->clearingBalance(''), 'the historical account must be emptied');
        self::assertSame(5_000, $this->clearingBalance('my-coolpay'), 'the provider account must hold the money');

        // Running it again moves nothing a second time.
        self::assertSame(0, $this->runCommand());

        self::assertSame(0, $this->clearingBalance(''));
        self::assertSame(5_000, $this->clearingBalance('my-coolpay'));

        // One motivated, audited transaction — nothing else.
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
        // The motive is carried by the write-up itself — NOT the project
        // id, which the transaction already carries in `project_id`.
        self::assertSame(self::REASON, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->value('external_ref'));
    }

    #[Test]
    public function it_shows_the_motive_in_its_summary(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(5_000);

        self::assertSame(0, $this->runCommand());

        self::assertStringContainsString(self::REASON, Artisan::output());
    }

    #[Test]
    public function it_refuses_to_guess_between_two_providers_and_leaves_the_balance_alone(): void
    {
        $this->providerApplication('my-coolpay');
        $this->providerApplication('orange-money');
        $this->historicalClearing(5_000);

        self::assertNotSame(0, $this->runCommand());
        self::assertStringContainsString('décision humaine', Artisan::output());

        self::assertSame(5_000, $this->clearingBalance(''), 'the balance must be left intact');
        self::assertSame(0, $this->clearingBalance('my-coolpay'));
        self::assertSame(0, $this->clearingBalance('orange-money'));
        self::assertSame(0, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    #[Test]
    public function it_says_when_a_project_has_no_provider_at_all(): void
    {
        $this->historicalClearing(5_000);

        self::assertNotSame(0, $this->runCommand());
        self::assertStringContainsString('aucun prestataire enregistré', Artisan::output());

        self::assertSame(5_000, $this->clearingBalance(''));
        self::assertSame(0, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    /**
     * ADR-0076 §2 — the historical account also receives the credits of
     * the wallet debits, which are NOT the provider's money. Moving them
     * would hand the provider money it never held.
     */
    #[Test]
    public function it_refuses_to_move_a_balance_that_is_not_explained_by_settlements(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(1_000);
        // An operational correction booked on the historical account: it
        // is not a settlement of the provider, so it is not the
        // provider's money.
        $this->nonSettlementDebit(500);

        self::assertNotSame(0, $this->runCommand());

        self::assertStringContainsString('résidu inexpliqué', Artisan::output());
        self::assertSame(1_500, $this->clearingBalance(''), 'the balance must be left intact');
        self::assertSame(0, $this->clearingBalance('my-coolpay'));
        self::assertSame(0, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    /**
     * A wallet debit no longer lands on the historical account — it
     * balances against the project's `withdrawal` account (ADR-0076 §2).
     * The money booked BEFORE that rule does stay there: the residue then
     * displayed is that historical share, to be settled by hand.
     */
    #[Test]
    public function it_refuses_and_shows_the_residue_left_by_the_wallet_debits_posted_before_the_fix(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(5_000);
        // A wallet debit of the old code: it credited the historical
        // project-level clearing account.
        $this->legacyWalletDebit(500);

        self::assertNotSame(0, $this->runCommand());

        self::assertStringContainsString('résidu inexpliqué', Artisan::output());
        self::assertSame(4_500, $this->clearingBalance(''), 'the balance must be left intact');
        self::assertSame(0, $this->clearingBalance('my-coolpay'));
        self::assertSame(0, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    /**
     * The idempotency key is used once per (project, currency,
     * provider): when the historical account receives money again, the
     * command must say so loudly instead of announcing a no-op.
     */
    #[Test]
    public function it_fails_loudly_when_the_reclassification_was_already_done_and_the_account_is_not_empty(): void
    {
        $this->providerApplication('my-coolpay');
        $this->historicalClearing(5_000);

        self::assertSame(0, $this->runCommand());

        // The account receives money again, this time a settlement the
        // command could identify — but the reclassification key is
        // already consumed.
        $this->historicalClearing(1_000);

        self::assertNotSame(0, $this->runCommand());

        self::assertStringContainsString('reclassement déjà effectué', Artisan::output());
        self::assertSame(1_000, $this->clearingBalance(''), 'nothing must be moved a second time');
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('type', 'clearing_reclassement')
            ->count());
    }

    private function runCommand(?string $reason = self::REASON): int
    {
        $parameters = ['--project' => self::PROJECT_ID];
        if ($reason !== null) {
            $parameters['--reason'] = $reason;
        }

        return Artisan::call('payment:reclassify-clearing', $parameters);
    }

    /**
     * A wallet debit booked BEFORE `DebitWalletUseCase` was aligned on the
     * `withdrawal` account: it credited the historical project-level
     * `clearing` account, which is not the provider's money.
     */
    private function legacyWalletDebit(int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $wallet = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'wallet', (string) Str::uuid(), 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'wallet_debit',
            (string) Str::uuid(),
            'legacy-'.Str::random(8),
            [
                new LedgerEntryInput($wallet->id, Direction::Debit, $amount, 'XAF'),
                new LedgerEntryInput($clearing->id, Direction::Credit, $amount, 'XAF'),
            ],
        ));
    }

    private function providerApplication(string $providerCode): void
    {
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: self::PROJECT_ID,
            providerCode: $providerCode,
            publicKey: 'public-'.$providerCode.'-'.Str::random(8),
            privateKey: 'private-'.$providerCode.'-'.Str::random(8),
        ));
    }

    /**
     * The money the historical (project-level) `clearing` account holds —
     * as booked before ADR-0076 §2.
     */
    private function historicalClearing(int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'payment_settlement',
            (string) Str::uuid(),
            'historical-'.Str::random(8),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amount, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, $amount, 'XAF'),
            ],
        ));
    }

    /**
     * A writing that is NOT a settlement of the provider — an
     * operational correction booked on the historical account.
     */
    private function nonSettlementDebit(int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'revenue', '', 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'ledger_adjustment',
            (string) Str::uuid(),
            'adjustment-'.Str::random(8),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amount, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, $amount, 'XAF'),
            ],
        ));
    }

    /**
     * The net debit of a `clearing` account of this project, by reference.
     */
    private function clearingBalance(string $reference): int
    {
        $accountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'clearing')
            ->where('reference', $reference)
            ->value('id');

        if ($accountId === null) {
            return 0;
        }

        $debit = DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'debit')->sum('amount_minor');
        $credit = DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'credit')->sum('amount_minor');

        return (int) $debit - (int) $credit;
    }
}
