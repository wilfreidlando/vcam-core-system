<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ApproveManualReceiptCommand;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\ManualReceiptView;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0073 §4, ADR-0094 §13, ADR-0095 — the offline collection of an
 * invoice, against a real PostgreSQL.
 *
 * The rules these tests pin are the ones the code could otherwise break
 * quietly:
 *
 *  - above the project's threshold, the receipt **waits** for a
 *    validation and books nothing;
 *  - the account that recorded the receipt **may validate it**
 *    (ADR-0095 withdrew the four eyes): one account is enough;
 *  - below the threshold, the receipt is accounted at the recording;
 *  - a refusal books nothing and leaves the trail.
 */
final class ManualReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '33333333-3333-4333-8333-333333333333';

    private const OPERATOR = '77777777-7777-4777-8777-777777777777';

    private const OTHER_OPERATOR = '88888888-8888-4888-8888-888888888888';

    private DateTimeImmutable $now;

    #[Test]
    public function a_receipt_above_the_threshold_waits_for_a_validation_and_books_nothing(): void
    {
        $this->threshold(10_000);
        $invoice = $this->issueInvoice();

        $receipt = app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: self::PROJECT,
            invoiceId: $invoice->id,
            method: 'bank_transfer',
            reference: 'VIR-2026-0001',
            amountMinor: 50_000,
            currency: 'XAF',
            receivedAt: '2026-10-27T08:00:00Z',
            recordedBy: self::OPERATOR,
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));

        self::assertSame('pending', $receipt->status);
        self::assertNull($receipt->approvedBy);

        // Nothing is booked: the receivable is still the one of the
        // issuance, and the money has not entered the books.
        self::assertSame(-50_000, $this->amountOf('customer_receivable'));
        self::assertSame(0, $this->amountOf('manual_receipts'));
        self::assertSame('open', $this->invoice($invoice->id)->status->value);
    }

    #[Test]
    public function the_account_that_recorded_the_receipt_may_validate_it(): void
    {
        $this->threshold(10_000);
        $invoice = $this->issueInvoice();

        $receipt = $this->record($invoice->id, 50_000);

        // ADR-0095 — one account is enough: the author may validate
        // their own entry.
        $validated = app(ApproveManualReceipt::class)->approve(new ApproveManualReceiptCommand(
            projectId: self::PROJECT,
            receiptId: $receipt->id,
            approver: self::OPERATOR,
            approved: true,
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));

        self::assertSame('approved', $validated->status);
        self::assertSame(self::OPERATOR, $validated->approvedBy);
        self::assertNotNull($validated->approvedAt);

        // The receivable is closed, and the money came in through the
        // account of the method.
        self::assertSame(0, $this->amountOf('customer_receivable'));
        self::assertSame(-50_000, $this->amountOf('manual_receipts'));
        self::assertSame('paid', $this->invoice($invoice->id)->status->value);
    }

    #[Test]
    public function a_receipt_below_the_threshold_is_accounted_at_the_recording(): void
    {
        $this->threshold(10_000);
        $invoice = $this->issueInvoice();

        $receipt = $this->record($invoice->id, 5_000);

        self::assertSame('approved', $receipt->status);
        self::assertNotNull($receipt->approvedAt);

        // The invoice was due for 50 000: 5 000 came in, 45 000 are
        // still due.
        self::assertSame(-45_000, $this->amountOf('customer_receivable'));
        self::assertSame(-5_000, $this->amountOf('manual_receipts'));
        self::assertSame('open', $this->invoice($invoice->id)->status->value);
    }

    #[Test]
    public function a_refused_receipt_books_nothing(): void
    {
        $this->threshold(10_000);
        $invoice = $this->issueInvoice();

        $receipt = $this->record($invoice->id, 50_000);

        $refused = app(ApproveManualReceipt::class)->approve(new ApproveManualReceiptCommand(
            projectId: self::PROJECT,
            receiptId: $receipt->id,
            approver: self::OTHER_OPERATOR,
            approved: false,
            actor: 'operator:'.self::OTHER_OPERATOR,
            correlationId: null,
        ));

        self::assertSame('rejected', $refused->status);

        // Nothing was booked, and the invoice is untouched.
        self::assertSame(-50_000, $this->amountOf('customer_receivable'));
        self::assertSame(0, $this->amountOf('manual_receipts'));
        self::assertSame('open', $this->invoice($invoice->id)->status->value);
    }

    #[Test]
    public function a_receipt_above_what_is_due_is_refused(): void
    {
        $this->threshold(10_000);
        $invoice = $this->issueInvoice();

        // The invoice is due for 50 000; a receipt of 70 000 would
        // over-pay it (ADR-0094 §10).
        $this->expectException(InvoiceNotPayable::class);

        $this->record($invoice->id, 70_000);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-10-28T09:00:00Z');

        // ADR-0087 — the time is injected.
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

    private function threshold(int $minor): void
    {
        $repository = app(BillingSettingsRepository::class);
        $settings = $repository->find(self::PROJECT);

        $repository->save(new BillingSettings(
            projectId: $settings->projectId,
            timezone: $settings->timezone,
            defaultCurrency: $settings->defaultCurrency,
            renewalLeadDays: $settings->renewalLeadDays,
            incompleteExpiryHours: $settings->incompleteExpiryHours,
            invoicePrefix: $settings->invoicePrefix,
            defaultTaxRateBps: $settings->defaultTaxRateBps,
            manualReceiptApprovalThresholdMinor: $minor,
            retainInvoiceIdentity: $settings->retainInvoiceIdentity,
            documentBranding: $settings->documentBranding,
        ));
    }

    private function record(string $invoiceId, int $amountMinor): ManualReceiptView
    {
        return app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: self::PROJECT,
            invoiceId: $invoiceId,
            method: 'bank_transfer',
            reference: 'VIR-2026-0001',
            amountMinor: $amountMinor,
            currency: 'XAF',
            receivedAt: '2026-10-27T08:00:00Z',
            recordedBy: self::OPERATOR,
            actor: 'operator:'.self::OPERATOR,
            correlationId: null,
        ));
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
