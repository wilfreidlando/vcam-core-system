<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §3 — when a counter goes back to zero.
 *
 * - `period` — reset at every subscription period;
 * - `never` — a gauge ("how many active students"), never reset.
 */
enum FeatureReset: string
{
    case Period = 'period';
    case Never = 'never';
}
