<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Application\UseCase\AllocateInvoicePaymentUseCase;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\InvoicePaymentStatus;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0073 §2, §3 — the collection of an invoice, online, against a
 * real PostgreSQL.
 *
 * The rules these tests pin are the ones the code could otherwise break
 * quietly:
 *
 *  - a settled collection is **imputed** on the receivable, and the
 *    invoice is closed;
 *  - a **partial** settlement is never imputed (ADR-0094 §10,
 *    ADR-0077): the money goes to the money exceptions, the invoice
 *    stays open;
 *  - a payment that lands on an invoice that is **already settled** is
 *    not lost: it moves to `payment_exceptions` and opens an
 *    overpayment (ADR-0073 §2);
 *  - a **replay** of the same status change moves nothing (the
 *    conditional transition, ADR-0073 §2).
 */
final class InvoicePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '33333333-3333-4333-8333-333333333333';

    private const OTHER_PROJECT = '44444444-4444-4444-8444-444444444444';

    private DateTimeImmutable $now;

    #[Test]
    public function a_settled_collection_is_imputed_and_closes_the_invoice(): void
    {
        $invoice = $this->issueInvoice();
        $attempt = $this->pendingAttempt($invoice->id, 50_000);

        // What the `payment` module books when the payment settles
        // (ADR-0076 §3): Débit `clearing:<prestataire>` / Crédit
        // `collections`.
        $this->bookTheCollection($attempt->paymentId, 50_000);

        $allocated = app(AllocateInvoicePaymentUseCase::class)->allocate(
            $attempt->paymentId,
            50_000,
            'XAF',
        );

        self::assertNotNull($allocated);
        self::assertSame('succeeded', $allocated->status);

        $stored = $this->invoice($invoice->id);

        self::assertSame('paid', $stored->status->value);
        self::assertSame(50_000, $stored->amountPaidMinor);
        self::assertSame(0, $stored->remainingMinor());
        self::assertNotNull($stored->paidAt);

        // The receivable is closed: the issuance booked −50 000 (the
        // ledger is credit-positive, ADR-0065), the imputation +50 000.
        self::assertSame(0, $this->amountOf('customer_receivable'));
    }

    #[Test]
    public function a_partial_settlement_is_never_imputed(): void
    {
        $invoice = $this->issueInvoice();
        $attempt = $this->pendingAttempt($invoice->id, 50_000);

        $this->bookTheCollection($attempt->paymentId, 50_000);

        // ADR-0094 §10 — the provider confirmed 30 000, not the 50 000
        // that was asked: nothing is imputed.
        $allocated = app(AllocateInvoicePaymentUseCase::class)->allocate(
            $attempt->paymentId,
            30_000,
            'XAF',
        );

        self::assertNull($allocated);

        $stored = $this->invoice($invoice->id);

        self::assertSame('open', $stored->status->value);
        self::assertSame(0, $stored->amountPaidMinor);
        self::assertSame(-50_000, $this->amountOf('customer_receivable'));

        // The attempt is closed with the reason, and the money is not
        // silently lost.
        $attemptRow = DB::table('invoice_payments')->where('id', $attempt->id)->first();

        self::assertNotNull($attemptRow);
        self::assertSame('failed', $attemptRow->status);

        $reason = $attemptRow->failure_reason;

        self::assertIsString($reason);
        self::assertStringContainsString('amount_mismatch', $reason);
    }

    #[Test]
    public function a_payment_that_lands_on_a_settled_invoice_is_not_lost(): void
    {
        $invoice = $this->issueInvoice();
        $first = $this->pendingAttempt($invoice->id, 50_000);
        $this->bookTheCollection($first->paymentId, 50_000);

        app(AllocateInvoicePaymentUseCase::class)->allocate($first->paymentId, 50_000, 'XAF');

        self::assertSame('paid', $this->invoice($invoice->id)->status->value);

        // A second attempt, started before the invoice was settled, that
        // lands afterwards: ADR-0073 §2.
        $late = $this->pendingAttempt($invoice->id, 50_000);
        $this->bookTheCollection($late->paymentId, 50_000);

        $allocated = app(AllocateInvoicePaymentUseCase::class)->allocate($late->paymentId, 50_000, 'XAF');

        self::assertNotNull($allocated);
        self::assertSame('succeeded', $allocated->status);

        // The invoice is not paid twice.
        self::assertSame(50_000, $this->invoice($invoice->id)->amountPaidMinor);

        // The money moved from `collections` to `payment_exceptions`.
        self::assertSame(0, $this->amountOf('collections'));
        self::assertSame(50_000, $this->amountOf('payment_exceptions'));

        // And an overpayment is open: nothing is silent.
        $exception = DB::table('money_exceptions')->where('project_id', self::PROJECT)->first();

        self::assertNotNull($exception);
        self::assertSame('overpayment', $exception->kind);
    }

    #[Test]
    public function a_replayed_allocation_moves_nothing(): void
    {
        $invoice = $this->issueInvoice();
        $attempt = $this->pendingAttempt($invoice->id, 50_000);
        $this->bookTheCollection($attempt->paymentId, 50_000);

        app(AllocateInvoicePaymentUseCase::class)->allocate($attempt->paymentId, 50_000, 'XAF');

        // The same event, delivered twice (the relay is at-least-once,
        // ADR-0040 §1).
        $replay = app(AllocateInvoicePaymentUseCase::class)->allocate($attempt->paymentId, 50_000, 'XAF');

        self::assertNull($replay);

        // The invoice is paid once, and the receivable is closed once.
        self::assertSame(50_000, $this->invoice($invoice->id)->amountPaidMinor);
        self::assertSame(0, $this->amountOf('customer_receivable'));
    }

    #[Test]
    public function a_payment_of_another_project_is_not_imputed(): void
    {
        $invoice = $this->issueInvoice();
        $attempt = $this->pendingAttempt($invoice->id, 50_000);
        $this->bookTheCollection($attempt->paymentId, 50_000);

        // The attempt belongs to another project: the Core never imputes
        // it here (G13, isolation of the projects).
        DB::table('invoice_payments')->where('id', $attempt->id)->update(['project_id' => self::OTHER_PROJECT]);

        $allocated = app(AllocateInvoicePaymentUseCase::class)->allocate($attempt->paymentId, 50_000, 'XAF');

        self::assertNull($allocated);
        self::assertSame('open', $this->invoice($invoice->id)->status->value);
        self::assertSame(-50_000, $this->amountOf('customer_receivable'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-10-28T09:00:00Z');

        // ADR-0087 — the time is injected: the test drives the
        // collection without waiting for it.
        $clock = new class($this->now) implements Clock
        {
            public function __construct(public DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };

        $this->app->bind(Clock::class, static fn (): Clock => $clock);
    }

    private function declareCustomer(): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'contact@acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    private function issueInvoice(): Invoice
    {
        $this->declareCustomer();

        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            lines: [new InvoiceLineInput(description: 'Mise en service', quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test:billing',
            correlationId: null,
        ));

        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        return $this->invoice($draft->id);
    }

    private function invoice(string $id): Invoice
    {
        $invoice = app(InvoiceRepository::class)->findById($id);

        self::assertNotNull($invoice);

        return $invoice;
    }

    private function pendingAttempt(string $invoiceId, int $amountMinor): InvoicePayment
    {
        $suffix = str_pad((string) random_int(1, 999_999), 12, '0', STR_PAD_LEFT);

        $attempt = new InvoicePayment(
            id: '55555555-5555-4555-8555-'.$suffix,
            invoiceId: $invoiceId,
            projectId: self::PROJECT,
            paymentId: '66666666-6666-4666-8666-'.$suffix,
            amountMinor: $amountMinor,
            currency: 'XAF',
            status: InvoicePaymentStatus::Pending,
            createdAt: $this->now,
            updatedAt: $this->now,
        );

        app(InvoicePaymentRepository::class)->save($attempt);

        return $attempt;
    }

    /**
     * What the `payment` module books when the payment settles
     * (ADR-0076 §3).
     */
    private function bookTheCollection(string $paymentId, int $amountMinor): void
    {
        $accounts = app(AccountResolver::class);

        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            self::PROJECT,
            'clearing',
            'my-coolpay',
            'XAF',
        ));

        $collections = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            self::PROJECT,
            'collections',
            '',
            'XAF',
        ));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            self::PROJECT,
            'payment_settlement',
            $paymentId,
            $paymentId,
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amountMinor, 'XAF'),
                new LedgerEntryInput($collections->id, Direction::Credit, $amountMinor, 'XAF'),
            ],
        ));
    }

    /**
     * The **signed** sum of the entries of an account kind — the balance
     * the account holds (a credit adds, a debit removes).
     */
    private function amountOf(string $kind): int
    {
        $row = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.kind', $kind)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN ledger_entries.direction = 'credit'"
                .' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END), 0) AS balance'
            )
            ->first();

        self::assertNotNull($row);

        $balance = $row->balance;

        self::assertIsNumeric($balance);

        return (int) $balance;
    }
}
