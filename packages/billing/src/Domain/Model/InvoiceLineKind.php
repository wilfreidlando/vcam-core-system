<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0073 §1 — the kind of an invoice line.
 *
 * The register is **open**: ADR-0090's extension points add `usage` with
 * the lot 12, and the migration that opens it will be an explicit,
 * traced change of constraint. Until then the base refuses anything but
 * these two — a typo in the field that decides the accounting nature of
 * a line must not pass.
 */
enum InvoiceLineKind: string
{
    /** The line comes from a period of a contract. */
    case Subscription = 'subscription';

    /** The line is free: a setup fee, a one-off service (ADR-0073 §1). */
    case Manual = 'manual';
}
