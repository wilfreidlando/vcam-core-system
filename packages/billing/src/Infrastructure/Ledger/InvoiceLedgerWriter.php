<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Ledger;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Domain\Model\Invoice;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\ManualReceipt;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;

/**
 * ADR-0073 §3 — the invoice in the ledger.
 *
 * The billing module speaks to the ledger only through its `Contracts/`
 * (G3c) and never the other way round (G2).
 *
 * The accounts it mints are **local to the module** — the chart of
 * accounts is open, and `billing` adds `customer_receivable`, `revenue`
 * and `bad_debt` to it (ADR-0076 §4). `customer_receivable` is
 * per-customer: its reference is the customer's id, so that the
 * receivable of a client is readable on its own account.
 *
 * Every writing carries a key of idempotence derived from the invoice
 * (`invoice:<id>:issue`, `invoice:<id>:void`) : a replay never doubles a
 * writing (ADR-0033 §2).
 */
final readonly class InvoiceLedgerWriter implements InvoiceLedger
{
    /** What the client owes — one account per customer. */
    private const CUSTOMER_RECEIVABLE = 'customer_receivable';

    /** What the project earned. */
    private const REVENUE = 'revenue';

    /** What will never be collected. */
    private const BAD_DEBT = 'bad_debt';

    /** Money collected, waiting to be imputed on a receivable (ADR-0076 §3). */
    private const COLLECTIONS = 'collections';

    /** Money collected offline, per method (ADR-0073 §3). */
    private const MANUAL_RECEIPTS = 'manual_receipts';

    /**
     * Money that arrived and cannot be imputed — the account the
     * `payment` module owns (`SettlementAccount::PAYMENT_EXCEPTIONS`).
     * The two modules share the literal, never the class: neither may
     * reach into the other's Domain (G3c).
     */
    private const PAYMENT_EXCEPTIONS = 'payment_exceptions';

    /** The reference of a project-level account — the one with no instance. */
    private const PROJECT_LEVEL = '';

    public function __construct(
        private AccountResolver $accounts,
        private LedgerPoster $poster,
    ) {}

    /**
     * The issuance: Débit `customer_receivable:<client>` / Crédit
     * `revenue`.
     */
    public function postIssuance(Invoice $invoice): string
    {
        $receivable = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::CUSTOMER_RECEIVABLE,
            $invoice->customerId,
            $invoice->currency,
        ));

        $revenue = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::REVENUE,
            self::PROJECT_LEVEL,
            $invoice->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $invoice->projectId,
            'invoice_issuance',
            'invoice:'.$invoice->number,
            'invoice:'.$invoice->id.':issue',
            [
                new LedgerEntryInput($receivable->id, Direction::Debit, $invoice->totalMinor, $invoice->currency),
                new LedgerEntryInput($revenue->id, Direction::Credit, $invoice->totalMinor, $invoice->currency),
            ],
        ));

        return $posted->id;
    }

    /**
     * The cancellation: the counter-passation of the issuance — the
     * receivable leaves the way it came, and the transaction says which
     * one it reverses (ADR-0076 §5).
     */
    public function postVoid(Invoice $invoice): string
    {
        $receivable = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::CUSTOMER_RECEIVABLE,
            $invoice->customerId,
            $invoice->currency,
        ));

        $revenue = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::REVENUE,
            self::PROJECT_LEVEL,
            $invoice->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $invoice->projectId,
            'invoice_void',
            'invoice:'.$invoice->number,
            'invoice:'.$invoice->id.':void',
            [
                new LedgerEntryInput($revenue->id, Direction::Debit, $invoice->totalMinor, $invoice->currency),
                new LedgerEntryInput($receivable->id, Direction::Credit, $invoice->totalMinor, $invoice->currency),
            ],
            $invoice->ledgerTransactionId,
        ));

        return $posted->id;
    }

    /**
     * The write-off: Débit `bad_debt` / Crédit
     * `customer_receivable:<client>`.
     */
    public function postUncollectible(Invoice $invoice): string
    {
        $receivable = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::CUSTOMER_RECEIVABLE,
            $invoice->customerId,
            $invoice->currency,
        ));

        $badDebt = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $invoice->projectId,
            self::BAD_DEBT,
            self::PROJECT_LEVEL,
            $invoice->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $invoice->projectId,
            'invoice_uncollectible',
            'invoice:'.$invoice->number,
            'invoice:'.$invoice->id.':uncollectible',
            [
                new LedgerEntryInput($badDebt->id, Direction::Debit, $invoice->totalMinor, $invoice->currency),
                new LedgerEntryInput($receivable->id, Direction::Credit, $invoice->totalMinor, $invoice->currency),
            ],
        ));

        return $posted->id;
    }

    /**
     * The imputation of a collection onto the receivable: Débit
     * `collections` / Crédit `customer_receivable:<client>`
     * (ADR-0073 §3).
     *
     * The key of idempotence is derived from the **attempt**, not from
     * the invoice: an invoice may have several attempts, and each one
     * is imputed at most once (ADR-0073 §3).
     */
    public function postAllocation(InvoicePayment $payment, Invoice $invoice, int $amountMinor): string
    {
        $collections = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $payment->projectId,
            self::COLLECTIONS,
            self::PROJECT_LEVEL,
            $payment->currency,
        ));

        $receivable = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $payment->projectId,
            self::CUSTOMER_RECEIVABLE,
            $invoice->customerId,
            $payment->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $payment->projectId,
            'invoice_payment_allocation',
            'invoice:'.$invoice->number,
            'invoice_payment:'.$payment->id.':allocate',
            [
                new LedgerEntryInput($collections->id, Direction::Debit, $amountMinor, $payment->currency),
                new LedgerEntryInput($receivable->id, Direction::Credit, $amountMinor, $payment->currency),
            ],
        ));

        return $posted->id;
    }

    /**
     * The money that arrived and cannot be imputed: Débit `collections`
     * / Crédit `payment_exceptions` (ADR-0073 §2).
     */
    public function postOverpayment(InvoicePayment $payment, int $amountMinor): string
    {
        $collections = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $payment->projectId,
            self::COLLECTIONS,
            self::PROJECT_LEVEL,
            $payment->currency,
        ));

        $exceptions = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $payment->projectId,
            self::PAYMENT_EXCEPTIONS,
            self::PROJECT_LEVEL,
            $payment->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $payment->projectId,
            'invoice_payment_overpayment',
            $payment->paymentId,
            'invoice_payment:'.$payment->id.':overpayment',
            [
                new LedgerEntryInput($collections->id, Direction::Debit, $amountMinor, $payment->currency),
                new LedgerEntryInput($exceptions->id, Direction::Credit, $amountMinor, $payment->currency),
            ],
        ));

        return $posted->id;
    }

    /**
     * The offline collection: Débit `manual_receipts:<moyen>` / Crédit
     * `customer_receivable:<client>` (ADR-0073 §3).
     */
    public function postManualReceipt(ManualReceipt $receipt, Invoice $invoice): string
    {
        $method = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $receipt->projectId,
            self::MANUAL_RECEIPTS,
            $receipt->method->value,
            $receipt->currency,
        ));

        $receivable = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $receipt->projectId,
            self::CUSTOMER_RECEIVABLE,
            $invoice->customerId,
            $receipt->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $receipt->projectId,
            'invoice_manual_receipt',
            'invoice:'.$invoice->number,
            'manual_receipt:'.$receipt->id.':allocate',
            [
                new LedgerEntryInput($method->id, Direction::Debit, $receipt->amountMinor, $receipt->currency),
                new LedgerEntryInput($receivable->id, Direction::Credit, $receipt->amountMinor, $receipt->currency),
            ],
        ));

        return $posted->id;
    }
}
