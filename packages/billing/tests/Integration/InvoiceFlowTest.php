<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MarkInvoiceUncollectibleCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0073 / ADR-0094 — the invoice, against a real PostgreSQL.
 *
 * The rules these tests pin are the ones the code could otherwise break
 * quietly:
 *
 *  - the contact frozen on an invoice is **stored in clear**
 *    (ADR-0123, ADR-0094 §2);
 *  - an issued invoice is **frozen** — by the database, by the trigger
 *    (ADR-0073 §1);
 *  - the issuance **books the receivable** in the ledger, and the
 *    cancellation counter-passes it (ADR-0073 §3);
 *  - the numbers of a project have **no hole** (ADR-0094 §5);
 *  - the tax is rounded half-up **on each line** (ADR-0094 §1).
 */
final class InvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '33333333-3333-4333-8333-333333333333';

    private const OTHER_PROJECT = '44444444-4444-4444-8444-444444444444';

    private DateTimeImmutable $now;

    #[Test]
    public function a_manual_invoice_is_created_draft_and_its_contact_is_stored_in_clear(): void
    {
        $this->declareCustomer();

        $invoice = $this->createInvoice();

        self::assertSame('draft', $invoice->status);
        self::assertNull($invoice->number);
        self::assertSame(50_000, $invoice->subtotalMinor);
        self::assertSame(0, $invoice->taxMinor);
        self::assertSame(50_000, $invoice->totalMinor);
        self::assertCount(1, $invoice->lines);

        $row = DB::table('invoices')->where('id', $invoice->id)->first();

        self::assertNotNull($row);

        // ADR-0123 — the frozen contact is in clear: the Core encrypts
        // nothing of the client any more.
        $raw = $row->customer_snapshot;
        self::assertIsString($raw);

        $snapshot = json_decode($raw, true);

        self::assertIsArray($snapshot);
        self::assertSame('acme', $snapshot['external_ref']);
        self::assertSame('ACME', $snapshot['display_name']);
        self::assertSame('contact@acme.test', $snapshot['email']);
        self::assertSame('+237690000000', $snapshot['phone']);

        // No key of the old encrypted format survives in the snapshot.
        foreach (array_keys($snapshot) as $key) {
            self::assertStringEndsNotWith('_ciphertext', (string) $key, sprintf(
                '`%s` is a key of the encrypted format: the snapshot holds the values in clear (ADR-0123).',
                (string) $key,
            ));
        }
    }

    #[Test]
    public function an_issued_invoice_carries_its_number_and_books_the_receivable(): void
    {
        $this->declareCustomer();

        $draft = $this->createInvoice();

        $issued = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        // ADR-0094 §5 — `<prefix>-<year>-<sequence>`, the sequence on six
        // digits.
        self::assertSame('open', $issued->status);
        self::assertNotNull($issued->number);
        self::assertMatchesRegularExpression('/^FACT-\d{4}-\d{6}$/', $issued->number);
        self::assertNotNull($issued->issuedAt);

        // ADR-0073 §3 — the issuance books the receivable: Débit
        // `customer_receivable:<client>` / Crédit `revenue`.
        //
        // The sign convention of the ledger is credit-positive
        // (ADR-0065): a receivable is therefore **negative** — the
        // client owes us that much.
        self::assertSame(['debit'], $this->directionsOf('customer_receivable'));
        self::assertSame(-50_000, $this->amountOf('customer_receivable'));
        self::assertSame(['credit'], $this->directionsOf('revenue'));
        self::assertSame(50_000, $this->amountOf('revenue'));
    }

    #[Test]
    public function an_issued_invoice_is_frozen_by_the_database(): void
    {
        $this->declareCustomer();

        $draft = $this->createInvoice();
        $issued = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        // A line slipped into an issued invoice.
        $this->expectException(QueryException::class);

        DB::table('invoice_lines')->insert([
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'invoice_id' => $issued->id,
            'project_id' => self::PROJECT,
            'kind' => 'manual',
            'description' => 'Ligne glissée',
            'quantity' => 1,
            'unit_amount_minor' => 1_000,
            'amount_minor' => 1_000,
            'tax_rate_bps' => 0,
            'tax_amount_minor' => 0,
            'period_start' => null,
            'period_end' => null,
            'created_at' => $this->now,
        ]);
    }

    #[Test]
    public function the_amounts_of_an_issued_invoice_cannot_move(): void
    {
        $this->declareCustomer();

        $draft = $this->createInvoice();
        $issued = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        $this->expectException(QueryException::class);

        DB::table('invoices')->where('id', $issued->id)->update(['total_minor' => 1]);
    }

    #[Test]
    public function a_void_invoice_counter_passes_its_issuance(): void
    {
        $this->declareCustomer();

        $draft = $this->createInvoice();
        $issued = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        $voided = app(VoidInvoice::class)->void(new VoidInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $issued->id,
            reason: 'Erreur de facturation',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('void', $voided->status);
        self::assertSame('Erreur de facturation', $voided->voidReason);
        self::assertNotNull($voided->voidedAt);

        // The document keeps its number: a cancelled invoice stays in the
        // series of the project.
        self::assertSame($issued->number, $voided->number);

        // ADR-0073 §3 — the receivable leaves the ledger the way it came.
        self::assertSame(['debit', 'credit'], $this->directionsOf('customer_receivable'));
        self::assertSame(0, $this->amountOf('customer_receivable'));
    }

    #[Test]
    public function the_write_off_keeps_the_instant_of_the_act(): void
    {
        $this->declareCustomer();

        $draft = $this->createInvoice();
        $issued = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        // A live receivable has no write-off date.
        self::assertNull($issued->lostAt);

        $written = app(MarkInvoiceUncollectible::class)->markUncollectible(new MarkInvoiceUncollectibleCommand(
            projectId: self::PROJECT,
            invoiceId: $issued->id,
            reason: 'Client injoignable',
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame('uncollectible', $written->status);
        // ADR-0094 §12 — the write-off is a human act: the date of that act
        // is kept, and it is the act's own date, not `updated_at`.
        self::assertNotNull($written->lostAt);
        // The column and the view write the same instant in two formats
        // (PostgreSQL's own, and ISO-8601) — the instant is what counts.
        $stored = DB::table('invoices')->where('id', $issued->id)->value('lost_at');
        self::assertIsString($stored);
        self::assertSame(
            (new DateTimeImmutable($stored))->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($written->lostAt))->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_numbers_of_a_project_have_no_hole(): void
    {
        $this->declareCustomer();

        $first = $this->createInvoice();
        $second = $this->createInvoice();

        $issuedFirst = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $first->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        $issuedSecond = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: self::PROJECT,
            invoiceId: $second->id,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertNotNull($issuedFirst->number);
        self::assertNotNull($issuedSecond->number);

        $sequenceOf = static fn (string $number): int => (int) substr($number, -6);

        // ADR-0094 §5 — consecutive, no gap, no duplicate.
        self::assertSame(
            $sequenceOf($issuedFirst->number) + 1,
            $sequenceOf($issuedSecond->number),
        );
    }

    #[Test]
    public function the_tax_is_rounded_half_up_on_each_line(): void
    {
        $this->declareCustomer();

        // 0.5 XAF at 50 % — the half rounds **up**.
        $invoice = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            lines: [new InvoiceLineInput(description: 'Demi', quantity: 1, unitAmountMinor: 1, taxRateBps: 5000)],
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame(1, $invoice->taxMinor);
        self::assertSame(2, $invoice->totalMinor);

        // The base knows the arithmetic too — it refuses anything else.
        $this->expectException(QueryException::class);

        DB::table('invoice_lines')
            ->where('invoice_id', $invoice->id)
            ->update(['tax_amount_minor' => 0]);
    }

    #[Test]
    public function the_invoice_of_another_project_is_not_found(): void
    {
        $this->declareCustomer();

        $invoice = $this->createInvoice();

        $found = app(GetInvoice::class)->get(
            new GetInvoiceCommand(self::OTHER_PROJECT, $invoice->id),
            AccessScope::forProject(self::OTHER_PROJECT),
        );

        self::assertNull($found);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-10-27T09:00:00Z');

        // ADR-0087 — the time is injected: the test drives the issuance
        // without waiting for it.
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

    /**
     * The directions of the entries of an account kind, in the order
     * they were written.
     *
     * @return list<mixed>
     */
    private function directionsOf(string $kind): array
    {
        return array_values(DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.kind', $kind)
            ->orderBy('ledger_entries.id')
            ->pluck('ledger_entries.direction')
            ->all());
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

    private function createInvoice(): InvoiceView
    {
        return app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: self::PROJECT,
            customerRef: 'acme',
            lines: [new InvoiceLineInput(description: 'Mise en service', quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test:billing',
            correlationId: null,
        ));
    }
}
