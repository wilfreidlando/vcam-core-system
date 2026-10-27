<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0070 §1 — the life cycle of a customer.
 *
 * - `active` — the customer has its rights;
 * - `suspended` — no rights, but the invoices stay due (ADR-0070 §2);
 * - `erased` — the identity is gone (ADR-0083), the history stays.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Erased = 'erased';
}
