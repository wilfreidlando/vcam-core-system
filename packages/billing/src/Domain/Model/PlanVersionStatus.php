<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §1 — the life cycle of a version of the catalogue.
 *
 * - `draft` — being written; the only editable state (ADR-0071 §2);
 * - `published` — the terms are frozen; the contracts signed on it keep
 *   them;
 * - `retired` — replaced by a newer version; the only transition a
 *   published version may undergo.
 */
enum PlanVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
