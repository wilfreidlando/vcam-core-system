<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Ledger;

use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Domain\Model\Invoice;
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
}
