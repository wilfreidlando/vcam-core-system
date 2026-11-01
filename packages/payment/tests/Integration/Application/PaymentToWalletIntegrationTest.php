<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Application;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGateway;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGatewayRegistry;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * The full Lot 3 path this whole session's ADR discussion (0036, 0037,
 * 0038) exists to make real: a payment is started against a real
 * Postgres-backed `Payment`, then a simulated provider webhook confirms
 * it succeeded — and because (and only because) the caller asked for a
 * wallet credit at initiation, the wallet is credited synchronously,
 * through the real `Core\Wallet`/`Core\Ledger` machinery from Lot 2.
 *
 * Only the outbound network call to the provider is replaced (a
 * `FakePaymentGateway` swapped in for `PaymentGatewayRegistry`) — routing
 * (`ConfigPaymentProviderRouter`), persistence (`EloquentPaymentRepository`),
 * the wallet crediting hookup, and the ledger underneath are all real,
 * against real PostgreSQL.
 */
final class PaymentToWalletIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000040';

    private FakePaymentGateway $gateway;

    #[Test]
    public function a_payment_with_no_wallet_instruction_is_booked_as_project_revenue_without_a_wallet(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: null));

        $this->confirmSuccess($view->id, confirmedAmount: 1_000);

        // Scoped to this test's own project: other integration tests in
        // this suite (e.g. WalletConcurrencyTest) deliberately commit
        // real, uncleaned rows outside RefreshDatabase — a global count
        // would see their residue too.
        self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());

        // ADR-0076 §3: every confirmed payment is written — clearing → revenue.
        self::assertSame([
            ['clearing', 'debit', 1_000],
            ['revenue', 'credit', 1_000],
        ], $this->settlementEntries($view->id));
    }

    #[Test]
    public function a_confirmed_amount_mismatch_credits_no_wallet_and_books_the_confirmed_amount_as_an_exception(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: (string) Str::uuid()));

        $this->confirmSuccess($view->id, confirmedAmount: 800);

        self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());
        self::assertSame('amount_mismatch', DB::table('payments')->where('id', $view->id)->value('settlement_exception'));
        self::assertEquals(800, DB::table('payments')->where('id', $view->id)->value('confirmed_amount_minor'));
        // ADR-0077 §2: the money the provider holds is real, so it is booked —
        // to payment_exceptions, never to a wallet.
        self::assertSame([
            ['clearing', 'debit', 800],
            ['payment_exceptions', 'credit', 800],
        ], $this->settlementEntries($view->id));
    }

    #[Test]
    public function a_replayed_success_books_the_settlement_only_once(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: null));

        $this->confirmSuccess($view->id, confirmedAmount: 1_000);
        $this->confirmSuccess($view->id, confirmedAmount: 1_000);

        self::assertSame(1, DB::table('ledger_transactions')->where('project_id', self::PROJECT_ID)->where('idempotency_key', $view->id)->count());
    }

    #[Test]
    public function a_payment_with_a_wallet_instruction_credits_the_wallet_once_confirmed(): void
    {
        $customerExternalRef = (string) Str::uuid();
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: $customerExternalRef));

        // Not credited yet — only initiated, still pending.
        self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());

        $this->confirmSuccess($view->id);

        $wallet = DB::table('wallets')->where('project_id', self::PROJECT_ID)->first();
        self::assertNotNull($wallet);
        self::assertSame($customerExternalRef, DB::table('parties')->where('id', $wallet->party_id)->value('external_ref'));

        $balance = DB::table('wallet_balances')->where('wallet_id', $wallet->id)->first();
        self::assertNotNull($balance);
        self::assertIsNumeric($balance->available_minor);
        self::assertSame(1_000, (int) $balance->available_minor);

        // The ledger's own truth agrees with the materialised balance.
        $walletAccountId = DB::table('ledger_accounts')
            ->where('project_id', self::PROJECT_ID)
            ->where('kind', 'wallet')
            ->where('reference', $wallet->id)
            ->value('id');
        $derivedBalance = 0;
        foreach (DB::table('ledger_entries')->where('account_id', $walletAccountId)->get() as $entry) {
            self::assertIsNumeric($entry->amount_minor);
            $amountMinor = (int) $entry->amount_minor;
            $derivedBalance += $entry->direction === 'credit' ? $amountMinor : -$amountMinor;
        }
        self::assertSame(1_000, $derivedBalance);
    }

    #[Test]
    public function replaying_the_same_webhook_credits_the_wallet_only_once(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: (string) Str::uuid()));

        $this->confirmSuccess($view->id);
        $this->confirmSuccess($view->id);

        $wallet = DB::table('wallets')->where('project_id', self::PROJECT_ID)->first();
        self::assertNotNull($wallet);
        $balance = DB::table('wallet_balances')->where('wallet_id', $wallet->id)->value('available_minor');
        self::assertIsNumeric($balance);
        self::assertSame(1_000, (int) $balance, 'a replayed confirmation must not double-credit');

        self::assertSame(1, DB::table('event_outbox')->where('type', 'payment.status_changed')->count());
    }

    #[Test]
    public function a_failed_payment_never_credits_the_wallet_even_with_an_instruction(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: (string) Str::uuid()));

        $providerTransactionRef = DB::table('payments')->where('id', $view->id)->value('provider_transaction_ref');
        self::assertIsString($providerTransactionRef);

        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $providerTransactionRef,
            appTransactionRef: 'irrelevant-here',
            status: PaymentStatus::Failed,
            reason: 'insufficient funds',
        ));

        self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());
        self::assertSame(PaymentStatus::Failed->value, DB::table('payments')->where('id', $view->id)->value('status'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway(ProviderCode::fromString('my-coolpay'));
        $registry = new FakePaymentGatewayRegistry;
        $registry->register($this->gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }

    private function command(?string $walletRecipientExternalRef): StartPaymentCommand
    {
        return new StartPaymentCommand(
            projectId: self::PROJECT_ID,
            channel: PaymentChannel::fromString('mtn_momo'),
            amount: 1_000,
            currency: 'XAF',
            reason: 'Order #42',
            idempotencyKey: (string) Str::uuid(),
            walletRecipientExternalRef: $walletRecipientExternalRef,
        );
    }

    private function confirmSuccess(string $paymentId, ?int $confirmedAmount = null): void
    {
        $providerTransactionRef = DB::table('payments')->where('id', $paymentId)->value('provider_transaction_ref');
        self::assertIsString($providerTransactionRef);

        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $providerTransactionRef,
            appTransactionRef: 'irrelevant-here',
            status: PaymentStatus::Succeeded,
            confirmedAmountMinor: $confirmedAmount,
            confirmedCurrency: $confirmedAmount === null ? null : 'XAF',
        ));
    }

    /**
     * The settlement transaction of a payment (keyed on its id), as
     * [account kind, direction, amount] rows, debit first.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function settlementEntries(string $paymentId): array
    {
        $transactionId = DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('idempotency_key', $paymentId)
            ->value('id');
        self::assertIsString($transactionId);

        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.transaction_id', $transactionId)
            ->orderBy('ledger_entries.direction', 'desc')
            ->get(['ledger_accounts.kind', 'ledger_entries.direction', 'ledger_entries.amount_minor']);

        $entries = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(stdClass::class, $row);
            self::assertIsString($row->kind);
            self::assertIsString($row->direction);
            self::assertIsInt($row->amount_minor);
            $entries[] = [$row->kind, $row->direction, $row->amount_minor];
        }

        return $entries;
    }
}
