<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * ADR-0077 §4 — how an exception is settled. Each one that moves money
 * books exactly the exception's `bookedAmount` out of `payment_exceptions`.
 */
enum MoneyExceptionResolution: string
{
    /** Money returned to the payer outside the platform: debit `payment_exceptions`, credit `withdrawal`. */
    case RefundedOutside = 'refunded_outside';

    /** Kept as project revenue: debit `payment_exceptions`, credit `revenue`. */
    case RecognizedAsRevenue = 'recognized_as_revenue';

    /** Allocated to an invoice by the module that owns it (billing). */
    case Allocated = 'allocated';

    /**
     * Imputed to a wallet: debit `payment_exceptions`, credit
     * `wallet:<partie>` (ADR-0077 §4).
     *
     * The wallet module posts that pair itself — it is the only one that
     * knows the wallet's ledger account — so this resolution names NO
     * ledger account of its own: `creditAccount()` returns `null` and
     * `LedgerSettlementLedger::recordExceptionResolution()` writes
     * nothing.
     */
    case AllocatedToWallet = 'allocated_to_wallet';

    /** Nothing to move: no money was booked for this exception (a control failure, an unbookable currency). */
    case NoAction = 'no_action';

    /** The ledger account the resolution credits; `null` when it books nothing. */
    public function creditAccount(): ?string
    {
        return match ($this) {
            self::RefundedOutside => SettlementAccount::WITHDRAWAL,
            self::RecognizedAsRevenue => SettlementAccount::REVENUE,
            self::Allocated, self::AllocatedToWallet, self::NoAction => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RefundedOutside => 'Remboursé hors plateforme',
            self::RecognizedAsRevenue => 'Passé en revenu du projet',
            self::Allocated => 'Imputé',
            self::AllocatedToWallet => 'Imputé à un wallet',
            self::NoAction => 'Aucun mouvement (rien n\'était écrit)',
        };
    }
}
