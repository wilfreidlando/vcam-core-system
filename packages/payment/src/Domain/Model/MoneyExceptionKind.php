<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/** ADR-0077 §3 — what kind of money problem an exception records. */
enum MoneyExceptionKind: string
{
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case Overpayment = 'overpayment';
    case LatePayment = 'late_payment';
    case UnallocatedAging = 'unallocated_aging';
    case ReconciliationGap = 'reconciliation_gap';
    case InvariantViolation = 'invariant_violation';

    public static function fromSettlement(SettlementException $exception): self
    {
        return match ($exception) {
            SettlementException::AmountMismatch => self::AmountMismatch,
            SettlementException::CurrencyMismatch => self::CurrencyMismatch,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AmountMismatch => 'Écart de montant',
            self::CurrencyMismatch => 'Écart de devise',
            self::Overpayment => 'Trop-perçu',
            self::LatePayment => 'Paiement tardif',
            self::UnallocatedAging => 'Encaissement non imputé',
            self::ReconciliationGap => 'Écart de rapprochement',
            self::InvariantViolation => 'Contrôle comptable en échec',
        };
    }
}
