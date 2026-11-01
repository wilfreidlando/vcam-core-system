<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §1 — the life cycle of a plan.
 *
 * - `draft` — being written; not proposed to anyone;
 * - `active` — in the catalogue;
 * - `archived` — no new subscription, the running ones continue.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
