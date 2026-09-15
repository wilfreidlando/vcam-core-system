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
    public function a_payment_with_no_wallet_instruction_never_touches_wallet_or_ledger(): void
    {
        $view = app(StartPayment::class)->start($this->command(walletRecipientExternalRef: null));

        $this->confirmSuccess($view->id);

        // Scoped to this test's own project: other integration tests in
        // this suite (e.g. WalletConcurrencyTest) deliberately commit
        // real, uncleaned rows outside RefreshDatabase — a global count
        // would see their residue too.
        self::assertSame(0, DB::table('wallets')->where('project_id', self::PROJECT_ID)->count());
        self::assertSame(0, $this->ledgerAccountIdsForProject()->count());
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

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function ledgerAccountIdsForProject(): \Illuminate\Support\Collection
    {
        $accountIds = DB::table('ledger_accounts')->where('project_id', self::PROJECT_ID)->pluck('id');

        return DB::table('ledger_entries')->whereIn('account_id', $accountIds)->get();
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

    private function confirmSuccess(string $paymentId): void
    {
        $providerTransactionRef = DB::table('payments')->where('id', $paymentId)->value('provider_transaction_ref');
        self::assertIsString($providerTransactionRef);

        app(PaymentStatusChangeHandler::class)->handle(new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: $providerTransactionRef,
            appTransactionRef: 'irrelevant-here',
            status: PaymentStatus::Succeeded,
        ));
    }
}
