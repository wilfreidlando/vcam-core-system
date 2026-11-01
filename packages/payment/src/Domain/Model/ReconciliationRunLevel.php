<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * ADR-0078 §1 — the three levels of control, independent of each other.
 */
enum ReconciliationRunLevel: string
{
    /** Level A — the Core's own financial invariants; no adapter capability needed. */
    case Invariants = 'invariants';

    /** Level B — the provider's statement against the `clearing:<p>` transactions. */
    case Transactions = 'transactions';

    /** Level C — the provider's balance against the `clearing:<p>` balance. */
    case Balance = 'balance';
}
