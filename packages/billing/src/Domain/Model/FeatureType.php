<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §3 — the two types of feature the Core knows.
 *
 * The registry of the types is extensible (ADR-0090), but the Core itself
 * only understands these two: a switch (on or off) and a counter (a limit
 * on a meter).
 */
enum FeatureType: string
{
    case Boolean = 'boolean';
    case Limit = 'limit';
}
