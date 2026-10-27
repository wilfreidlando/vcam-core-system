<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0072 §1 — the nature of a period of a contract.
 *
 * - `trial` — the free trial granted at the signature;
 * - `paid` — the billed period, the only one that carries an invoice;
 * - `complimentary` — the period granted by the operator, free and
 *   motivated (ADR-0072 §6).
 */
enum PeriodKind: string
{
    case Trial = 'trial';
    case Paid = 'paid';
    case Complimentary = 'complimentary';
}
