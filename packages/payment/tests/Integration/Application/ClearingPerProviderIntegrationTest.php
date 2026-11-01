<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Application;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\MoneyExceptionPoolInsufficient;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentPurpose;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\ResolveMoneyException;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\Domain\Exception\UnsupportedPaymentPurpose;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGateway;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGatewayRegistry;
use Core\Support\Access\AccessScope;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * ADR-0076 §2 — the money a provider holds for a project now sits on
 * the PROVIDER's own account (`clearing:<prestataire>`), not on the
 * historical project-level one (reference `''`). Without that, the
 * reconciliation (ADR-0078 §3/§4) and the payouts (ADR-0079) cannot
 * isolate a provider.
 *
 * ADR-0077 §4 — an exception of money can be imputed to a wallet: the
 * money leaves `payment_exceptions` and lands on the wallet of the
 * party the payment was for — but only what that pool really holds.
 */
final class ClearingPerProviderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000000c1';

    private FakePaymentGateway $gateway;

    #[Test]
    public function a_direct_settlement_debits_the_providers_clearing_account_and_leaves_the_project_account_alone(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: null));

        $this->confirmSuccess($view->id);

        self::assertSame([
            ['clearing', 'my-coolpay', 'debit', 1_000],
            ['revenue', '', 'credit', 1_000],
        ], $this->entriesOf($view->id));

        // The historical project-level account is not touched at all.
        self::assertSame(0, $this->entriesOnHistoricalClearing());
    }

    #[Test]
    public function a_wallet_credit_debits_the_providers_clearing_account_and_leaves_the_project_account_alone(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: (string) Str::uuid()));

        $this->confirmSuccess($view->id);

        $walletId = DB::table('wallets')->where('project_id', self::PROJECT_ID)->value('id');
        self::assertIsString($walletId);

        self::assertSame([
            ['clearing', 'my-coolpay', 'debit', 1_000],
            ['wallet', $walletId, 'credit', 1_000],
        ], $this->entriesOf($view->id));

        self::assertSame(0, $this->entriesOnHistoricalClearing());
    }

    #[Test]
    public function an_exception_imputed_to_a_wallet_debits_the_exception_pool_and_credits_the_wallet(): void
    {
        $this->fundExceptionPool(1_000);
        $exceptionId = $this->openException(1_000, 'customer-ext-1');

        $view = app(ResolveMoneyException::class)->resolve(
            new ResolveMoneyExceptionCommand(
                exceptionId: $exceptionId,
                resolution: 'allocated_to_wallet',
                operatorId: '01a0a1cc-0000-7000-8000-0000000000ff',
                reference: null,
                note: 'Le trop-perçu revient au client.',
            ),
            AccessScope::platform(),
        );

        self::assertSame('resolved', $view->status);
        self::assertSame('allocated_to_wallet', $view->resolution);

        $walletId = DB::table('wallets')->where('project_id', self::PROJECT_ID)->value('id');
        self::assertIsString($walletId);

        $available = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
        self::assertEquals(1_000, $available, 'the wallet balance must have grown by the imputed amount');

        self::assertSame([
            ['payment_exceptions', '', 'debit', 1_000],
            ['wallet', $walletId, 'credit', 1_000],
        ], $this->entriesOf('money_exception:'.$exceptionId.':wallet'));

        // The pool gave back exactly what it held.
        self::assertSame(0, $this->poolBalance());
    }

    /**
     * ADR-0077 §4 — an exception whose money was never written to the
     * grand livre holds nothing on `payment_exceptions`: imputing it to a
     * wallet would take money that was never collected.
     */
    #[Test]
    public function an_exception_the_pool_does_not_hold_is_refused_and_credits_no_wallet(): void
    {
        $exceptionId = $this->openException(5_000, 'customer-ext-1');

        $this->expectException(MoneyExceptionPoolInsufficient::class);

        try {
            app(ResolveMoneyException::class)->resolve(
                new ResolveMoneyExceptionCommand(
                    exceptionId: $exceptionId,
                    resolution: 'allocated_to_wallet',
                    operatorId: '01a0a1cc-0000-7000-8000-0000000000ff',
                    reference: null,
                    note: 'Le trop-perçu revient au client.',
                ),
                AccessScope::platform(),
            );
        } finally {
            // Nothing moved: no wallet, no ledger entry, and the
            // exception is still open.
            self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());
            self::assertSame(0, DB::table('ledger_transactions')
                ->where('project_id', self::PROJECT_ID)
                ->where('idempotency_key', 'money_exception:'.$exceptionId.':wallet')
                ->count());
            self::assertSame('open', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));
            self::assertSame(0, $this->poolBalance());
        }
    }

    /**
     * ADR-0076 §3, ADR-0094 §9 — the INTENTION decides the account the
     * money is booked on.
     */
    #[Test]
    public function an_invoice_settlement_is_credited_to_the_collections_account_and_not_to_the_revenue(): void
    {
        $view = app(StartPayment::class)->start($this->command(
            walletRecipientExternalRef: null,
            purpose: PaymentPurpose::INVOICE,
        ));

        $this->confirmSuccess($view->id);

        self::assertSame([
            ['clearing', 'my-coolpay', 'debit', 1_000],
            ['collections', '', 'credit', 1_000],
        ], $this->entriesOf($view->id));

        // The revenue was not touched: `billing` will allocate the
        // collection onto the receivable, and crediting the revenue here
        // would count the same money twice.
        self::assertSame(0, DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.kind', 'revenue')
            ->count());
    }

    #[Test]
    public function a_payment_without_a_wallet_is_a_plain_collection(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: null));

        self::assertSame(PaymentPurpose::DIRECT, $view->purpose);
    }

    #[Test]
    public function a_payment_with_a_wallet_is_a_wallet_credit(): void
    {
        $view = app(StartPayment::class)->start($this->command(
            walletRecipientExternalRef: (string) Str::uuid(),
        ));

        self::assertSame(PaymentPurpose::WALLET_CREDIT, $view->purpose);
    }

    #[Test]
    public function an_unknown_purpose_is_refused(): void
    {
        $this->expectException(UnsupportedPaymentPurpose::class);

        app(StartPayment::class)->start($this->command(
            walletRecipientExternalRef: null,
            purpose: 'facture',
        ));
    }

    #[Test]
    public function a_wallet_credit_cannot_be_claimed_without_a_wallet(): void
    {
        $this->expectException(UnsupportedPaymentPurpose::class);

        app(StartPayment::class)->start($this->command(
            walletRecipientExternalRef: null,
            purpose: PaymentPurpose::WALLET_CREDIT,
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway(ProviderCode::fromString('my-coolpay'));
        $registry = new FakePaymentGatewayRegistry;
        $registry->register($this->gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }

    private function command(?string $walletRecipientExternalRef, ?string $purpose = null): StartPaymentCommand
    {
        return new StartPaymentCommand(
            projectId: self::PROJECT_ID,
            channel: PaymentChannel::fromString('mtn_momo'),
            amount: 1_000,
            currency: 'XAF',
            reason: 'Order #42',
            idempotencyKey: (string) Str::uuid(),
            walletRecipientExternalRef: $walletRecipientExternalRef,
            purpose: $purpose,
        );
    }

    private function confirmSuccess(string $paymentId): void
    {
        $providerTransactionRef = DB::table('payments')->where('id', $paymentId)->value('provider_transaction_ref');
        self::assertIsString($providerTransactionRef);

        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $providerTransactionRef,
            appTransactionRef: 'irrelevant-here',
            status: PaymentStatus::Succeeded,
            confirmedAmountMinor: 1_000,
            confirmedCurrency: 'XAF',
        ));
    }

    /**
     * An exception in the queue, booking `$bookedAmount` on the pool.
     */
    private function openException(int $bookedAmount, ?string $subjectRef): string
    {
        $exceptionId = (string) Str::uuid();
        app(MoneyExceptionRepository::class)->create(MoneyException::open(
            id: $exceptionId,
            projectId: self::PROJECT_ID,
            kind: MoneyExceptionKind::AmountMismatch,
            severity: MoneyExceptionSeverity::High,
            amount: $bookedAmount,
            currency: 'XAF',
            bookedAmount: $bookedAmount,
            paymentId: null,
            subjectRef: $subjectRef,
            summary: 'Trop-perçu de '.$bookedAmount.' XAF.',
            details: [],
            now: new DateTimeImmutable('2026-10-27T10:00:00Z'),
        ));

        return $exceptionId;
    }

    /**
     * What the provider confirmed and the Core could not allocate lands
     * on the exception pool — as an unallocated settlement does.
     */
    private function fundExceptionPool(int $amount): void
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'clearing', 'my-coolpay', 'XAF'));
        $pool = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(self::PROJECT_ID, 'payment_exceptions', '', 'XAF'));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT_ID,
            'payment_unallocated',
            (string) Str::uuid(),
            'pool-'.Str::random(8),
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amount, 'XAF'),
                new LedgerEntryInput($pool->id, Direction::Credit, $amount, 'XAF'),
            ],
        ));
    }

    /**
     * What the exception pool holds, in minor units.
     */
    private function poolBalance(): int
    {
        $accountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'payment_exceptions')
            ->where('reference', '')
            ->value('id');

        if ($accountId === null) {
            return 0;
        }

        $debit = DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'debit')->sum('amount_minor');
        $credit = DB::table('ledger_entries')->where('account_id', $accountId)->where('direction', 'credit')->sum('amount_minor');

        return (int) $credit - (int) $debit;
    }

    /**
     * The entries of the transaction keyed on `$idempotencyKey`, as
     * [kind, reference, direction, amount] rows, debit first.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function entriesOf(string $idempotencyKey): array
    {
        $transactionId = DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
        self::assertIsString($transactionId);

        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.transaction_id', $transactionId)
            ->orderBy('ledger_entries.direction', 'desc')
            ->get(['ledger_accounts.kind', 'ledger_accounts.reference', 'ledger_entries.direction', 'ledger_entries.amount_minor']);

        $entries = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(stdClass::class, $row);
            self::assertIsString($row->kind);
            self::assertIsString($row->reference);
            self::assertIsString($row->direction);
            self::assertIsInt($row->amount_minor);
            $entries[] = [$row->kind, $row->reference, $row->direction, $row->amount_minor];
        }

        return $entries;
    }

    /**
     * How many entries touch the historical project-level `clearing`
     * account (reference `''`) of this project — it must stay at zero.
     */
    private function entriesOnHistoricalClearing(): int
    {
        return DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.project_id', self::PROJECT_ID)
            ->where('ledger_accounts.kind', 'clearing')
            ->where('ledger_accounts.reference', '')
            ->count();
    }
}
